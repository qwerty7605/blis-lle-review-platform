import http from './http'

class AuditApi {
  list(params = {}) {
    return http.get('/admin/audit-logs', { params })
  }

  // The same filters as list(), as a file: params.format is xlsx or csv.
  exportCsv(params = {}) {
    return http.get('/admin/audit-logs/export', { params, responseType: 'blob' })
  }
}

export const auditApi = new AuditApi()
