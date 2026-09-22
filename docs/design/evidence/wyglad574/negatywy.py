from pathlib import Path
import subprocess,shutil,os,tempfile,hashlib,json
r=Path('/home/mateusz/kuking-work560');c=Path('/mnt/c/Users/matma/Documents/Codex/kuking.pl');out=c/'output/wyglad574';backup=Path(tempfile.mkdtemp(prefix='wyglad574-',dir='/home/mateusz/kuking-local'));env=os.environ.copy();env['PATH']='/opt/kuking-php-8.4-avif/bin:/home/mateusz/.nvm/versions/node/v24.19.0/bin:'+env['PATH'];env['APP_BASE_PATH']=str(r);results=[]
php=['python3',str(c/'output/php560.py'),'--compact','--filter=SzybkiWygladTest']
browser=['node',str(c/'output/wyglad-neg-browser.mjs')]
cases=[('cookie','resources/views/components/layout.blade.php',"$guestScale = (int) request()->cookie(config('kuking.text.cookie'), 100);","$guestScale = 100;",php),('konto','app/Http/Controllers/ThemeController.php',"$request->user()->update($data);","// zapis celowo wyłączony",php),('cofniecie','resources/js/szybki-wyglad.js','apply(saved);','// cofnięcie celowo wyłączone',browser),('geometria','resources/css/szybki-wyglad.css','right: 0;','right: 100vw;',browser)]
cases.append(('polozenie-konta','resources/js/szybki-wyglad.js','nav.getBoundingClientRect().height > 0 && ', '', ['node',str(c/'output/wyglad-desktop.mjs')]))
for name,file,old,new,cmd in cases:
 p=r/file;copy=backup/name;shutil.copy2(p,copy);before=hashlib.md5(p.read_bytes()).hexdigest();mtime=p.stat().st_mtime_ns;assert old in p.read_text()
 try:
  p.write_text(p.read_text().replace(old,new));changed=hashlib.md5(p.read_bytes()).hexdigest()
  if cmd!=php:subprocess.run(['npm','run','build'],cwd=r,env=env,stdout=subprocess.DEVNULL,check=True)
  subprocess.run(['php','artisan','view:clear'],cwd=r,env=env,stdout=subprocess.DEVNULL,check=True)
  run=subprocess.run(cmd,cwd=r,env=env,capture_output=True,text=True);(out/(name+'.log')).write_text(run.stdout+run.stderr);assert run.returncode!=0,'Nie wykryto '+name
 finally:
  shutil.copy2(copy,p);assert hashlib.md5(p.read_bytes()).hexdigest()==before;assert p.stat().st_mtime_ns==mtime
  if cmd!=php:subprocess.run(['npm','run','build'],cwd=r,env=env,stdout=subprocess.DEVNULL,check=True)
  subprocess.run(['php','artisan','view:clear'],cwd=r,env=env,stdout=subprocess.DEVNULL,check=True)
  positive=subprocess.run(cmd,cwd=r,env=env,capture_output=True,text=True);(out/(name+'-restore.log')).write_text(positive.stdout+positive.stderr);assert positive.returncode==0,'Brak PASS po restore '+name
 results.append({'case':name,'before':before,'changed':changed,'restored':hashlib.md5(p.read_bytes()).hexdigest(),'mtime_restored':True,'negative':run.returncode,'positive':positive.returncode})
(out/'negatywy.json').write_text(json.dumps(results,indent=2));print('5 negatywów i 5 przywróceń PASS')
