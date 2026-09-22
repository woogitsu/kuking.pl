from pathlib import Path
import subprocess,hashlib,shutil,tempfile,json
r=Path('/home/mateusz/kuking-370-delivery');p=r/'resources/views/pages/tags/index.blade.php';out=Path('/mnt/c/Users/matma/Documents/Codex/kuking-370/output');b=Path(tempfile.mkdtemp(prefix='kuking370-order-'))/'index.blade.php';shutil.copy2(p,b)
def sig():return [hashlib.md5(p.read_bytes()).hexdigest(),p.stat().st_mtime_ns]
def test():
 subprocess.run(['/opt/kuking-php-8.4-avif/bin/php','artisan','view:clear'],cwd=r,check=True,stdout=subprocess.DEVNULL)
 return subprocess.run(['python3',str(out/'targeted.py')],capture_output=True,text=True)
before=sig();data={'before':before,'backup':str(b)}
try:
 s=p.read_text();assert '@foreach($polecane as $tag)' in s;p.write_text(s.replace('@foreach($polecane as $tag)','@foreach($polecane->reverse() as $tag)'))
 n=test();data['negative']={'exit':n.returncode,'output':n.stdout+n.stderr};assert n.returncode!=0 and 'kolejnosci_gospodarza' in n.stdout
finally:
 shutil.copy2(b,p);assert sig()==before;data['restored']=sig()
z=test();data['positive']={'exit':z.returncode,'output':z.stdout+z.stderr};(out/'negative-order.json').write_text(json.dumps(data,indent=2));assert z.returncode==0;print('Order negative detected; source restored; positive 10 tests PASS')
