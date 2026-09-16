/** Connection presets for managed infra services (matches README defaults). */

const HTTP_URL = /^https?:\/\//i

/**
 * @typedef {{ labelKey: string, value: string, web?: boolean }} ConnectionField
 * @typedef {{ fields: ConnectionField[], env: string, notes?: string[], webUrl?: string }} ConnectionDetails
 */

/** @param {ConnectionField[] | undefined} fields */
function webUrlFromFields(fields) {
  if (!fields?.length) return null
  const marked = fields.find((entry) => entry.web && HTTP_URL.test(entry.value || ''))
  if (marked?.value) return marked.value
  const withUrl = fields.find((entry) => HTTP_URL.test(entry.value || ''))
  return withUrl?.value || null
}

/** @type {Record<string, ConnectionDetails>} */
const DETAILS = {
  mysql: {
    fields: [
      { labelKey: 'services.conn.host_docker', value: 'mysql' },
      { labelKey: 'services.conn.host_local', value: '127.0.0.1' },
      { labelKey: 'services.conn.port', value: '3306' },
      { labelKey: 'services.conn.user', value: 'root' },
      { labelKey: 'services.conn.password', value: '1' },
    ],
    env: [
      'DB_HOST=mysql',
      'DB_PORT=3306',
      'DB_USERNAME=root',
      'DB_PASSWORD=1',
    ].join('\n'),
  },
  postgres: {
    fields: [
      { labelKey: 'services.conn.host_docker', value: 'postgres' },
      { labelKey: 'services.conn.host_local', value: '127.0.0.1' },
      { labelKey: 'services.conn.port', value: '5432' },
      { labelKey: 'services.conn.database', value: 'postgres' },
      { labelKey: 'services.conn.user', value: 'postgres' },
      { labelKey: 'services.conn.password', value: '1' },
    ],
    env: [
      'DB_CONNECTION=pgsql',
      'DB_HOST=postgres',
      'DB_PORT=5432',
      'DB_DATABASE=postgres',
      'DB_USERNAME=postgres',
      'DB_PASSWORD=1',
    ].join('\n'),
  },
  redis: {
    fields: [
      { labelKey: 'services.conn.host_docker', value: 'redis' },
      { labelKey: 'services.conn.host_local', value: '127.0.0.1' },
      { labelKey: 'services.conn.port', value: '6379' },
      { labelKey: 'services.conn.password', value: '(none)' },
    ],
    env: ['REDIS_HOST=redis', 'REDIS_PORT=6379'].join('\n'),
  },
  rabbitmq: {
    fields: [
      { labelKey: 'services.conn.host_docker', value: 'rabbitmq' },
      { labelKey: 'services.conn.host_local', value: '127.0.0.1' },
      { labelKey: 'services.conn.port_amqp', value: '5672' },
      { labelKey: 'services.conn.port_mgmt', value: '15672' },
      { labelKey: 'services.conn.user', value: 'admin' },
      { labelKey: 'services.conn.password', value: 'admin' },
      {
        labelKey: 'services.conn.web_url',
        value: 'http://localhost:15672',
        web: true,
      },
    ],
    env: [
      'RABBITMQ_HOST=rabbitmq',
      'RABBITMQ_PORT=5672',
      'RABBITMQ_USER=admin',
      'RABBITMQ_PASSWORD=admin',
    ].join('\n'),
  },
  kafka: {
    fields: [
      { labelKey: 'services.conn.broker_docker', value: 'kafka:29092' },
      { labelKey: 'services.conn.broker_local', value: 'localhost:9092' },
    ],
    env: ['KAFKA_BROKERS=kafka:29092'].join('\n'),
    notes: ['services.conn.kafka_note'],
  },
  mailpit: {
    fields: [
      { labelKey: 'services.conn.host_docker', value: 'mailpit' },
      { labelKey: 'services.conn.host_local', value: '127.0.0.1' },
      { labelKey: 'services.conn.port_smtp', value: '1025' },
      { labelKey: 'services.conn.port_web', value: '8025' },
      {
        labelKey: 'services.conn.web_url',
        value: 'http://localhost:8025',
        web: true,
      },
    ],
    env: [
      'MAIL_MAILER=smtp',
      'MAIL_HOST=mailpit',
      'MAIL_PORT=1025',
      'MAIL_USERNAME=null',
      'MAIL_PASSWORD=null',
      'MAIL_ENCRYPTION=null',
    ].join('\n'),
    notes: ['services.conn.mailpit_note'],
  },
  minio: {
    fields: [
      { labelKey: 'services.conn.host_docker', value: 'minio' },
      { labelKey: 'services.conn.host_local', value: '127.0.0.1' },
      { labelKey: 'services.conn.port_api', value: '9000' },
      { labelKey: 'services.conn.port_console', value: '9001' },
      { labelKey: 'services.conn.user', value: 'minioadmin' },
      { labelKey: 'services.conn.password', value: 'minioadmin' },
      {
        labelKey: 'services.conn.web_url',
        value: 'http://localhost:9001',
        web: true,
      },
    ],
    env: [
      'AWS_ACCESS_KEY_ID=minioadmin',
      'AWS_SECRET_ACCESS_KEY=minioadmin',
      'AWS_DEFAULT_REGION=us-east-1',
      'AWS_BUCKET=local',
      'AWS_ENDPOINT=http://minio:9000',
      'AWS_USE_PATH_STYLE_ENDPOINT=true',
    ].join('\n'),
    notes: ['services.conn.minio_note'],
  },
}

/** @returns {ConnectionDetails | null} */
export function getInfraConnectionDetails(service) {
  return DETAILS[service] || null
}

export function hasInfraConnectionDetails(service) {
  return Boolean(DETAILS[service])
}

/** @returns {string | null} */
export function getInfraWebUrl(service) {
  if (!service) return null
  const details = DETAILS[service]
  if (!details) return null
  if (typeof details.webUrl === 'string' && details.webUrl) return details.webUrl
  return webUrlFromFields(details.fields)
}

/**
 * Web UI for a Services card row (core infra or custom compose with matching service id).
 * @param {{ kind: 'infra' | 'compose', service?: string, item?: { service?: string | null } }} row
 * @returns {string | null}
 */
export function getServiceRowWebUrl(row) {
  if (row.kind === 'infra') return getInfraWebUrl(row.service)
  if (row.kind === 'compose') {
    const id = row.item?.service
    if (typeof id === 'string' && id) return getInfraWebUrl(id)
  }
  return null
}

export function hasServiceRowWebAccess(row) {
  return Boolean(getServiceRowWebUrl(row))
}

export function hasInfraWebAccess(service) {
  return Boolean(getInfraWebUrl(service))
}

export function openInfraWeb(service) {
  const url = getInfraWebUrl(service)
  if (!url) return
  window.open(url, '_blank', 'noopener,noreferrer')
}
