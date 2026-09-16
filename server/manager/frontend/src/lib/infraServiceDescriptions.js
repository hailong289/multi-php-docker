/** Core infra service ids with a short UI description (i18n key services.desc.*). */
export const INFRA_DESC_SERVICES = [
  'mysql',
  'postgres',
  'redis',
  'rabbitmq',
  'kafka',
  'mailpit',
  'minio',
]

/** @returns {string | null} i18n key */
export function infraServiceDescriptionKey(service) {
  if (!service || !INFRA_DESC_SERVICES.includes(service)) return null
  return `services.desc.${service}`
}
