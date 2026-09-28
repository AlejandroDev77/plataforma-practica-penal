from __future__ import annotations

import io
import math
import shutil
import zipfile
from pathlib import Path
from typing import Literal

import pymupdf
from docx import Document
from docx.document import Document as DocumentObject
from docx.table import Table
from docx.text.paragraph import Paragraph
from PIL import Image, UnidentifiedImageError

from app.core.config import Settings
from app.modules.ingestion.schemas import (
    DocumentExtractionResult,
    ExtractedPage,
    ExtractionWarning,
)

MAX_DOCX_EXPANDED_BYTES = 200_000_000
MAX_DOCX_ENTRIES = 5_000
ALLOWED_IMAGE_FORMATS = {"JPEG", "PNG", "TIFF"}


class DocumentExtractionError(Exception):
    def __init__(self, code: str, message: str, status_code: int = 422) -> None:
        super().__init__(message)
        self.code = code
        self.message = message
        self.status_code = status_code


class DocumentExtractor:
    """Extrae texto en memoria y no conserva copias ni rutas de los documentos."""

    def __init__(self, settings: Settings) -> None:
        self.settings = settings

    def extract(self, filename: str, content: bytes) -> DocumentExtractionResult:
        if not content:
            raise DocumentExtractionError("empty_file", "El archivo está vacío.")
        if len(content) > self.settings.document_max_bytes:
            limit_mb = self.settings.document_max_bytes // 1_048_576
            raise DocumentExtractionError(
                "file_too_large",
                f"El archivo supera el límite de {limit_mb} MB.",
                413,
            )

        extension = Path(filename).suffix.lower()
        if extension == ".pdf":
            return self._extract_pdf(content)
        if extension == ".docx":
            return self._extract_docx(content)
        if extension in {".jpg", ".jpeg", ".png", ".tif", ".tiff"}:
            return self._extract_image(content, extension)

        raise DocumentExtractionError(
            "unsupported_format",
            "Formato no admitido. Use PDF, DOCX, JPG, PNG o TIFF.",
            415,
        )

    def _extract_pdf(self, content: bytes) -> DocumentExtractionResult:
        if b"%PDF-" not in content[:1024]:
            raise DocumentExtractionError("invalid_pdf", "El archivo no contiene un PDF válido.")

        try:
            document = pymupdf.open(stream=content, filetype="pdf")
        except Exception as error:
            raise DocumentExtractionError(
                "invalid_pdf", "No se pudo abrir el documento PDF."
            ) from error

        with document:
            if document.needs_pass:
                raise DocumentExtractionError(
                    "password_protected_pdf",
                    "El PDF está protegido con contraseña y no se puede procesar.",
                )
            self._validate_page_count(document.page_count)

            pages: list[ExtractedPage] = []
            warnings: list[ExtractionWarning] = []
            for index, page in enumerate(document, start=1):
                native_text = self._normalize_text(page.get_text("text", sort=True))
                text = native_text
                used_ocr = False

                if len(native_text) < self.settings.page_ocr_threshold_characters:
                    ocr_text, warning_code = self._ocr_page(page)
                    if warning_code is not None:
                        warnings.append(self._warning(warning_code, index))
                    elif ocr_text:
                        text = ocr_text
                        used_ocr = True
                    else:
                        warnings.append(self._warning("text_not_recognized", index))

                pages.append(
                    ExtractedPage(
                        page_number=index,
                        locator=f"Página {index}",
                        text=text,
                        used_ocr=used_ocr,
                        confidence=None,
                        is_readable=bool(text),
                    )
                )

        return DocumentExtractionResult(
            document_type="pdf",
            page_count=len(pages),
            pages=pages,
            warnings=warnings,
        )

    def _extract_docx(self, content: bytes) -> DocumentExtractionResult:
        self._validate_docx_package(content)
        try:
            document: DocumentObject = Document(io.BytesIO(content))
        except Exception as error:
            raise DocumentExtractionError(
                "invalid_docx", "No se pudo abrir el documento DOCX."
            ) from error

        blocks: list[str] = []
        for block in document.iter_inner_content():
            if isinstance(block, Paragraph):
                text = self._normalize_text(block.text)
                if text:
                    blocks.append(text)
            elif isinstance(block, Table):
                for row in block.rows:
                    cells = [self._normalize_text(cell.text) for cell in row.cells]
                    row_text = " | ".join(value for value in cells if value)
                    if row_text:
                        blocks.append(row_text)

        text = "\n\n".join(blocks)
        return DocumentExtractionResult(
            document_type="docx",
            page_count=None,
            pages=[
                ExtractedPage(
                    page_number=1,
                    locator="Documento completo (paginación física no disponible)",
                    text=text,
                    used_ocr=False,
                    confidence=None,
                    is_readable=bool(text),
                )
            ],
            warnings=[
                ExtractionWarning(
                    code="pagination_unavailable",
                    message=(
                        "DOCX extraído como documento completo; no se inventaron números de página."
                    ),
                )
            ],
        )

    def _extract_image(self, content: bytes, extension: str) -> DocumentExtractionResult:
        try:
            with Image.open(io.BytesIO(content)) as image:
                image_format = image.format
                if image_format not in ALLOWED_IMAGE_FORMATS:
                    raise DocumentExtractionError(
                        "unsupported_format",
                        "La imagen debe ser JPG, PNG o TIFF.",
                        415,
                    )
                self._validate_image_extension(extension, image_format)
                page_count = getattr(image, "n_frames", 1)
                self._validate_page_count(page_count)

                pages: list[ExtractedPage] = []
                warnings: list[ExtractionWarning] = []
                for index in range(page_count):
                    image.seek(index)
                    if image.width * image.height > self.settings.page_max_pixels:
                        raise DocumentExtractionError(
                            "image_dimensions_exceeded",
                            "Una página de imagen supera el límite de resolución permitido.",
                            413,
                        )

                    with image.copy() as source_frame:
                        frame = source_frame.convert("RGB")
                        try:
                            png = io.BytesIO()
                            frame.save(png, format="PNG")
                        finally:
                            frame.close()

                    page_document = pymupdf.open(stream=png.getvalue(), filetype="png")
                    with page_document:
                        text, warning_code = self._ocr_page(page_document[0])

                    if warning_code is not None:
                        warnings.append(self._warning(warning_code, index + 1))
                    elif not text:
                        warnings.append(self._warning("text_not_recognized", index + 1))

                    pages.append(
                        ExtractedPage(
                            page_number=index + 1,
                            locator=f"Imagen {index + 1}" if page_count > 1 else "Imagen",
                            text=text,
                            used_ocr=bool(text),
                            confidence=None,
                            is_readable=bool(text),
                        )
                    )
        except DocumentExtractionError:
            raise
        except (UnidentifiedImageError, Image.DecompressionBombError, OSError, ValueError) as error:
            raise DocumentExtractionError(
                "invalid_image", "No se pudo abrir la imagen enviada."
            ) from error

        return DocumentExtractionResult(
            document_type="image",
            page_count=len(pages),
            pages=pages,
            warnings=warnings,
        )

    def _ocr_page(self, page: pymupdf.Page) -> tuple[str, str | None]:
        executable = self.settings.ocr_tesseract_path
        if (executable and not Path(executable).is_file()) or (
            not executable and shutil.which("tesseract") is None
        ):
            return "", "ocr_unavailable"

        tessdata = self.settings.ocr_tessdata_path
        try:
            dpi = self._safe_ocr_dpi(page)
            if dpi is None:
                return "", "ocr_failed"
            text_page = page.get_textpage_ocr(
                language=self.settings.ocr_language,
                dpi=dpi,
                full=True,
                tessdata=tessdata,
            )
            text = self._normalize_text(page.get_text("text", textpage=text_page, sort=True))
        except Exception:
            return "", "ocr_failed"

        return text, None

    def _safe_ocr_dpi(self, page: pymupdf.Page) -> int | None:
        requested = max(72, self.settings.ocr_dpi)
        width = max(1.0, page.rect.width)
        height = max(1.0, page.rect.height)
        if width * height > self.settings.page_max_pixels:
            return None
        pixels = width * height * (requested / 72) ** 2
        if pixels <= self.settings.page_max_pixels:
            return requested

        return max(72, int(requested * math.sqrt(self.settings.page_max_pixels / pixels)))

    def _validate_page_count(self, count: int) -> None:
        if count < 1:
            raise DocumentExtractionError("empty_document", "El documento no contiene páginas.")
        if count > self.settings.document_max_pages:
            raise DocumentExtractionError(
                "page_limit_exceeded",
                f"El documento supera el límite de {self.settings.document_max_pages} páginas.",
                413,
            )

    @staticmethod
    def _normalize_text(text: str) -> str:
        return "\n".join(line.strip() for line in text.replace("\x00", "").splitlines()).strip()

    @staticmethod
    def _validate_image_extension(extension: str, image_format: str) -> None:
        valid_extensions = {
            "JPEG": {".jpg", ".jpeg"},
            "PNG": {".png"},
            "TIFF": {".tif", ".tiff"},
        }
        if extension not in valid_extensions[image_format]:
            raise DocumentExtractionError(
                "file_type_mismatch",
                "La extensión del archivo no coincide con el tipo real de imagen.",
            )

    @staticmethod
    def _validate_docx_package(content: bytes) -> None:
        try:
            with zipfile.ZipFile(io.BytesIO(content)) as archive:
                entries = archive.infolist()
                if len(entries) > MAX_DOCX_ENTRIES:
                    raise DocumentExtractionError(
                        "invalid_docx", "El DOCX contiene demasiados elementos internos."
                    )
                if sum(entry.file_size for entry in entries) > MAX_DOCX_EXPANDED_BYTES:
                    raise DocumentExtractionError(
                        "docx_expanded_size_exceeded",
                        "El contenido interno del DOCX supera el límite permitido.",
                        413,
                    )
                names = {entry.filename for entry in entries}
                if "[Content_Types].xml" not in names or "word/document.xml" not in names:
                    raise DocumentExtractionError(
                        "invalid_docx", "El archivo no tiene una estructura DOCX válida."
                    )
        except DocumentExtractionError:
            raise
        except (zipfile.BadZipFile, OSError) as error:
            raise DocumentExtractionError(
                "invalid_docx", "El archivo no tiene una estructura DOCX válida."
            ) from error

    @staticmethod
    def _warning(
        code: Literal["ocr_unavailable", "ocr_failed", "text_not_recognized"],
        page_number: int,
    ) -> ExtractionWarning:
        messages = {
            "ocr_unavailable": (
                "No se pudo leer esta página porque el OCR no está disponible en el servidor."
            ),
            "ocr_failed": "No se pudo completar el OCR de esta página.",
            "text_not_recognized": "No se reconoció texto legible en esta página.",
        }
        return ExtractionWarning(code=code, page_number=page_number, message=messages[code])
