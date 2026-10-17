const fs=require('node:fs'),assert=require('node:assert/strict'),P=require('php-parser');
for(const n of ['catalog-starters.php','catalog-data.php','catalog-api.php','business.php','platform-api.php','telegram.php','index.php']){new P({parser:{version:'8.1'}}).parseCode(fs.readFileSync(__dirname+'/../public/'+n,'utf8'));console.log(n+' syntax PASS');}
const source=fs.readFileSync(__dirname+'/../public/catalog-starters.php','utf8');
const kits=JSON.parse(source.split("<<<'JSON'")[1].split('JSON,true)')[0]);
const business=fs.readFileSync(__dirname+'/../public/business.php','utf8');
const typePart=business.split('function businessTypes(){return [')[1].split('];}')[0];
const types=[...typePart.matchAll(/'([a-z]+)'\s*=>/g)].map(x=>x[1]);
assert.deepEqual(Object.keys(kits).sort(),types.sort());
for(const [type,rows] of Object.entries(kits)){assert(rows.length>=4);const names=new Set();for(const r of rows){assert(r[0]&&!names.has(r[0]));names.add(r[0]);assert(Number.isFinite(r[1])&&r[1]>0);assert(r[2]&&r[3]&&r[4]&&r[5]);if(r[6])assert(['available','out_of_stock','preorder','unknown'].includes(r[6]));}}
assert(kits.cafe.find(x=>x[0]==='Капучино')[1]===1200);
assert(kits.cafe.find(x=>x[0]==='Мороженое ванильное')[5].includes('балмұздақ'));
assert(kits.cafe.find(x=>x[0]==='Тирамису')[6]==='out_of_stock');
console.log('PASS: all '+types.length+' business types covered; '+Object.values(kits).flat().length+' valid illustrative positions.');
