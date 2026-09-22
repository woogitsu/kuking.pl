from pathlib import Path
import subprocess,shutil,hashlib,json,tempfile
root=Path('/home/mateusz/kuking-370-browser'); source=root/'resources/views/pages/tags/show.blade.php'
out=Path('/mnt/c/Users/matma/Documents/Codex/kuking-370/output/browser370')
backup=Path(tempfile.mkdtemp(prefix='kuking370-blade-negative-'))/'show.blade.php';shutil.copy2(source,backup)
def signature(p):return {'md5':hashlib.md5(p.read_bytes()).hexdigest(),'mtime_ns':p.stat().st_mtime_ns}
def build():subprocess.run(['/opt/kuking-php-8.4-avif/bin/php','artisan','view:clear'],cwd=root,check=True,stdout=subprocess.DEVNULL)
def probe():return subprocess.run(['node',str(out/'preselection.mjs')],capture_output=True,text=True)
before=signature(source);results={'before':before,'backup':str(backup)}
try:
 s=source.read_text();old="route('posts.create', ['tag' => $tag->slug])";assert old in s
 source.write_text(s.replace(old,"route('posts.create')"))
 build();r=probe();results['negative']={'exit':r.returncode,'output':r.stdout+r.stderr};assert r.returncode!=0 and 'TAG_PRESELECTION_MISSING' in r.stderr
finally:
 shutil.copy2(backup,source);results['restored']=signature(source);assert results['restored']==before;build()
r=probe();results['positive']={'exit':r.returncode,'output':r.stdout+r.stderr};(out/'negative-blade.json').write_text(json.dumps(results,indent=2));assert r.returncode==0
print('Blade negative detected; MD5/mtime restored; positive PASS')
