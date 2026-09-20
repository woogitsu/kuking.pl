import { validationIsolation } from './panel-validation-isolation.mjs';
// Local acceptance helper. Capture sensitive snapshot only in memory.
import { execFile } from 'node:child_process';
import { realpathSync } from 'node:fs';

export function createSnapshotCallback({ php, script, base, fullManifest, statesManifest, env }) {
  const root = realpathSync(base);
  validationIsolation(env);
  if (env.APP_BASE_PATH !== root) throw new Error('SNAPSHOT_CALLBACK_CONFIG');
  const executable = php; // Ta sama jawna binarka/PATH co w istniejacym runnerze.
  const args = [realpathSync(script), root, realpathSync(fullManifest), realpathSync(statesManifest)];
  const childEnv = { ...env };
  return () => new Promise((resolve, reject) => {
    execFile(executable, args, {
      cwd: root, env: childEnv, encoding: 'utf8', timeout: 30000,
      maxBuffer: 32 * 1024 * 1024, windowsHide: true,
    }, (error, stdout, stderr) => {
      // Do not forward Error/cause/stdout/stderr: they can contain whole user rows.
      if (error || stderr.length > 0) {
        reject(new Error('SNAPSHOT_CALLBACK_FAILED'));
        return;
      }
      try {
        const value = JSON.parse(stdout);
        if (!value || typeof value !== 'object' || !value.isolation || !value.domain) {
          throw new Error('shape');
        }
        resolve(value);
      } catch {
        reject(new Error('SNAPSHOT_CALLBACK_INVALID'));
      }
    });
  });
}
