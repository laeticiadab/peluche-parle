import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const html = readFileSync(new URL('../geo-ar.html', import.meta.url), 'utf8');
const validation = html.match(/const valid=o=>[^\n]+/)?.[0];
const bearing = html.match(/function distanceBearing\(a,b\)\{[^\n]+/)?.[0];
assert.ok(validation && bearing, 'Fonctions géographiques introuvables');
const ctx = {};
vm.runInNewContext('const rad=d=>d*Math.PI/180; const deg=r=>r*180/Math.PI;\n' +
  validation + '\n' + bearing + '\nthis.validate=valid;this.measure=distanceBearing;', ctx);
const example = { id: 'demo', name: 'Ours', lat: 12.37, lng: -1.52, kind: 'bear', scale: 2, alt: 0 };

test('validation des coordonnées, modèles et URLs', () => {
  assert.equal(ctx.validate(example), true);
  assert.equal(ctx.validate({ ...example, lat: 91 }), false);
  assert.equal(ctx.validate({ ...example, scale: -2 }), false);
  assert.equal(ctx.validate({ ...example, kind: 'glb', url: 'http://invalid/obj.glb' }), false);
  assert.equal(ctx.validate({ ...example, kind: 'glb', url: 'https://cdn.test/obj.glb' }), true);
});
test('distance et cap géographiques', () => {
  assert.equal(ctx.measure({ lat: 0, lng: 0 }, { lat: 0, lng: 0 }).distance, 0);
  const north = ctx.measure({ lat: 12, lng: -1 }, { lat: 12.01, lng: -1 });
  assert.ok(north.distance > 1000 && north.distance < 1200);
  assert.ok(Math.abs(north.bearing) < 0.001);
  const east = ctx.measure({ lat: 12, lng: -1 }, { lat: 12, lng: -0.99 });
  assert.ok(Math.abs(east.bearing - 90) < 0.01);
  const wrap = ctx.measure({ lat: 0, lng: 179.99 }, { lat: 0, lng: -179.99 });
  assert.ok(wrap.distance > 2000 && wrap.distance < 2400);
});
test('IDs HTML uniques et réglage manuel disponible', () => {
  const ids = [...html.matchAll(/\bid="([^"]+)"/g)].map(m => m[1]);
  assert.equal(ids.length, new Set(ids).size);
  for (const m of html.matchAll(/\$\('([^']+)'\)/g)) assert.ok(ids.includes(m[1]), 'ID absent : ' + m[1]);
  assert.ok(html.includes('heading=0,headingAvailable=false'));
  assert.ok(html.includes("headingAvailable?'(capteur)':'(manuel)'"));
});
