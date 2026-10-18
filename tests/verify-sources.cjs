const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const Parser = require('php-parser');
const root = path.resolve(__dirname, '..');
const publicDir = path.join(root, 'public');
const parser = new Parser({ parser: { version: '8.1' } });
for (const name of fs.readdirSync(publicDir)) {
  const content = fs.readFileSync(path.join(publicDir, name), 'utf8');
  if (name.endsWith('.php')) parser.parseCode(content, name);
  if (name.endsWith('.js')) new vm.Script(content, { filename: name });
  assert(!/dbname=p-\d+/.test(content), name + ': private database identifier');
  assert(!/\b\d{8,15}:[A-Za-z0-9_-]{30,}\b/.test(content), name + ': possible bot token');
  assert(!/\b(?:AIza[A-Za-z0-9_-]{30,}|gh[opusr]_[A-Za-z0-9]{25,})\b/.test(content), name + ': possible API token');
  assert(!/\bAQ\.[A-Za-z0-9_-]{30,}/.test(content), name + ': possible provider key');
  assert(!/\bIGA[A-Za-z0-9_-]{50,}/.test(content), name + ': possible Instagram token');
}
let index = fs.readFileSync(path.join(publicDir, 'index.php'), 'utf8');
index = index.replace(/<\?php readfile\(__DIR__\.'\/([^']+)'\); \?>/g,
  (_, name) => fs.readFileSync(path.join(publicDir, name), 'utf8'));
const scripts = [...index.matchAll(/<script>([\s\S]*?)<\/script>/g)]
  .map(match => match[1].replace(/<\?php[\s\S]*?\?>/g, '{}')).join('\n');
new vm.Script(scripts, { filename: 'assembled-application.js' });
assert(!fs.existsSync(path.join(publicDir, 'aulieata-secrets.php')));
assert(!fs.existsSync(path.join(root, 'aulieata-secrets.php')));
const config = fs.readFileSync(path.join(root, 'aulieata-secrets.example.php'), 'utf8');
for (const key of ['db_user', 'db_pass', 'gemini_key', 'telegram_token', 'owner_phone', 'webhook_secret', 'encryption_key']) {
  assert(config.includes("'" + key + "' => ''"), key + ' must be empty in example');
}
console.log('PASS: all PHP 8.1 syntax, JavaScript including assembled UI, private config exclusion and token checks.');
