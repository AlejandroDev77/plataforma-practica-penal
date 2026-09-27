export function downloadCsv(filename: string, rows: string[][]) {
  const escape = (value: string) => `"${value.replace(/^[=+@\-\t\r]/, "'$&").replaceAll('"', '""')}"`
  const blob = new Blob(['\ufeff' + rows.map((row) => row.map(escape).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

export function shortDate(date: string) {
  const value = /^\d{4}-\d{2}-\d{2}$/.test(date) ? new Date(`${date}T12:00:00`) : new Date(date)
  return new Intl.DateTimeFormat('es-BO', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  }).format(value)
}
