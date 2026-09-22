import os,subprocess,base64
r='/home/mateusz/kuking-370-form-tests';e=os.environ.copy();e.update(PATH='/opt/kuking-php-8.4-avif/bin:'+e['PATH'],APP_BASE_PATH=r,APP_ENV='testing',APP_KEY='base64:'+base64.b64encode(os.urandom(32)).decode(),DB_CONNECTION='pgsql',DB_HOST='127.0.0.1',DB_PORT='55439',DB_DATABASE='kuking_370_form_tests',DB_USERNAME='kuking',DB_PASSWORD='',DB_URL='',MAIL_MAILER='array',CACHE_STORE='array',SESSION_DRIVER='array',QUEUE_CONNECTION='sync')
raise SystemExit(subprocess.run(['php','vendor/bin/phpunit','--filter=SpisTematowTest','--display-warnings'],cwd=r,env=e).returncode)
