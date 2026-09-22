// Two explicit configurations: local acceptance and the dedicated CI service.
export function validationIsolation(env) {
  const ci = env.GITHUB_ACTIONS === 'true';
  const database = ci ? 'kuking_port_panel' : 'kuking_581_validation';
  const port = String(env.DB_PORT || '');
  if (!['local', 'testing'].includes(env.APP_ENV) || env.DB_HOST !== '127.0.0.1'
      || env.DB_DATABASE !== database || env.MAIL_MAILER !== 'array'
      || !/^\d+$/.test(port) || Number(port) < 1 || Number(port) > 65535
      || (!ci && port !== '55439')) throw new Error('VALIDATION_ISOLATION');
  return { database, port, ci };
}
