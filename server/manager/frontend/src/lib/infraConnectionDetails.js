/** Connection presets for managed infra services (matches README defaults). */

/**
 * @typedef {{ labelKey: string, value: string }} ConnectionField
 * @typedef {{ fields: ConnectionField[], env: string, notes?: string[] }} ConnectionDetails
 */

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
      { labelKey: 'services.conn.mgmt_url', value: 'http://localhost:15672' },
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
}

/** @returns {ConnectionDetails | null} */
export function getInfraConnectionDetails(service) {
  return DETAILS[service] || null
}

export function hasInfraConnectionDetails(service) {
  return Boolean(DETAILS[service])
}
