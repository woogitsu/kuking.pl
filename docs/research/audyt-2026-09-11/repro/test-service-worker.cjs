'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const handlers = {};
const stores = new Map();
let version = 'deployment-A';
let networkRequests = 0;
const origin = 'https://example.test';
const key = req => new URL(typeof req === 'string' ? req : req.url, origin).href;
const response = body => ({ok:true, type:'basic', body, clone() { return response(this.body); }});
const caches = {
  async open(name) {
    if (!stores.has(name)) stores.set(name, new Map());
    const store = stores.get(name);
    return {
      async put(req, value) { store.set(key(req), value); },
      async addAll(urls) { for (const url of urls) store.set(key(url), response(version)); }
    };
  },
  async match(req) { for (const store of stores.values()) if (store.has(key(req))) return store.get(key(req)); },
  async keys() { return [...stores.keys()]; },
  async delete(name) { return stores.delete(name); }
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'sw-reviewed.js'),'utf8'), {
  URL, caches,
  self: {location:{origin}, clients:{claim:async()=>{}}, skipWaiting:async()=>{}, addEventListener:(type, fn)=>{handlers[type]=fn;}},
  fetch: async () => { networkRequests++; return response(version); }
}, {filename:'sw-reviewed.js'});
async function get(url) {
  let result;
  handlers.fetch({request:{url:new URL(url,origin).href,method:'GET',mode:'cors'}, respondWith:p=>{result=p;}});
  const value = await result;
  await new Promise(resolve=>setImmediate(resolve));
  return value;
}
(async () => {
  const initial = await get('/manifest.webmanifest');
  version = 'deployment-B';
  const afterDeploy = await get('/manifest.webmanifest');
  assert.equal(initial.body, 'deployment-A');
  assert.equal(afterDeploy.body, 'deployment-A');
  assert.equal(networkRequests, 1);
  await get('/build/assets/app-A.js');
  await get('/build/assets/app-B.js');
  assert(stores.get('kuking-v1').has(key('/build/assets/app-A.js')));
  const other = await caches.open('unrelated-app-cache');
  await other.put('/unrelated-file',response('unrelated'));
  let activation;
  handlers.activate({waitUntil:p=>{activation=p;}});
  await activation;
  assert(!stores.has('unrelated-app-cache'));
  console.log(JSON.stringify({
    scope:'Isolated execution of reviewed service worker statements; mocked CacheStorage/network, not a browser E2E test.',
    checks:3,
    manifest:{first:initial.body,afterServerDeployment:afterDeploy.body,networkRequests:1,staleValueReproduced:true},
    oldHashedAssetRetained:true,
    unrelatedCacheDeletedOnActivation:true
  },null,2));
})().catch(e=>{console.error(e);process.exitCode=1;});
