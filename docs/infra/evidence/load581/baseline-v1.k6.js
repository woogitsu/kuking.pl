import http from 'k6/http';
import exec from 'k6/execution';
import { check } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';

// Private manifest is created after real login; never include it in evidence.
const manifest = JSON.parse(open(__ENV.LOAD_MANIFEST));
const base = manifest.base;
if (!/^http:\/\/127\.0\.0\.1:8185$/.test(base)) throw new Error('Only isolated load581 origin allowed');
const kind = __ENV.LOAD_KIND || 'public';
const paths = manifest[kind];
if (!Array.isArray(paths) || !paths.length) throw new Error('Missing verified routes');
const useful = new Counter('useful_responses');
const throttled = new Counter('responses_429');
const failed = new Rate('user_request_failed');
const latency = new Trend('successful_latency', true);
export const options = {
  scenarios: { baseline: {executor:'constant-arrival-rate',rate:Number(__ENV.LOAD_RATE||1),timeUnit:'1s',duration:__ENV.LOAD_DURATION||'60s',preAllocatedVUs:20,maxVUs:20,gracefulStop:'15s'} },
  summaryTrendStats:['min','med','p(95)','p(99)','max'],
  systemTags:['status','method','name','scenario','expected_response'],
  thresholds: {successful_latency:[{threshold:'p(95)<5000',abortOnFail:true,delayAbortEval:'20s'}],user_request_failed:[{threshold:'rate<0.05',abortOnFail:true,delayAbortEval:'20s'}]},
};
for(const target of paths) options.thresholds['successful_latency{endpoint:'+target.name+'}']=[];
export default function () {
  const target=paths[exec.scenario.iterationInTest % paths.length];
  if (!target.path.startsWith('/') || target.path.startsWith('//')) throw new Error('Invalid local path');
  const headers={};
  if(kind!=='public') {
    const session=manifest.sessions[exec.vu.idInTest-1];
    if(!session) throw new Error('Each VU requires separate authenticated session');
    headers.Cookie=session.cookie;
  }
  const requestedPath=target.path.replace('{collection}',kind==='public'?'':manifest.sessions[exec.vu.idInTest-1].collection);
  const started=Date.now();
  const response=http.get(base+requestedPath,{headers,redirects:target.name==='image'?3:0,timeout:'10s',tags:{name:target.name}});
  const ok=check(response,{'expected status and real content':r=>r.status===target.status && (!target.marker || r.body.includes(target.marker)) && (!target.forbidden || !r.body.includes(target.forbidden))});
  if(!ok) console.log('FAILED endpoint='+target.name+' status='+response.status);
  failed.add(!ok,{endpoint:target.name});
  if(response.status===429) throttled.add(1,{endpoint:target.name});
  if(ok && response.status===200) { useful.add(1,{endpoint:target.name});latency.add(Date.now()-started,{endpoint:target.name}); }
}
export function handleSummary(data) {
  return {[__ENV.LOAD_SUMMARY||'summary.json']:JSON.stringify(data,null,2)};
}