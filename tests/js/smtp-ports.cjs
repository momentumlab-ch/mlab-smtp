'use strict';
// Exercise the actual admin script with dependency-free DOM/event fixtures.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
class Field {
 constructor(name, value, type = 'text') { Object.assign(this, { name, value, type, checked: false, disabled: false, readOnly: false, listeners: {}, dataset: {}, textContent: '' }); }
 addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }
 dispatchEvent(event) { for (const fn of this.listeners[event.type] || []) fn(event); }
}
const primaryPort = new Field('smtp_port', '587');
const backupPort = new Field('fb_smtp_port', '587');
const radios = ['tls', 'ssl', 'none'].map(value => new Field('smtp_secure', value, 'radio'));
radios[0].checked = true;
const backup = new Field('fb_smtp_secure', 'tls', 'select-one');
const hints = { smtp_secure: new Field('', ''), fb_smtp_secure: new Field('', '') };
for (const hint of Object.values(hints)) hint.dataset.template = 'Usual port: %s. Custom ports remain unchanged.';
let focused = false; let scrolled = false;
const feedback = { focus: () => { focused = true; }, scrollIntoView: () => { scrolled = true; } };
const fields = [primaryPort, backupPort, ...radios, backup];
const root = {
 querySelector(selector) {
  if (selector === '[data-mlab-step-feedback]') return feedback;
  const hint = selector.match(/^\[data-mlab-port-hint="([^"]+)"\]$/);
  if (hint) return hints[hint[1]];
  const name = selector.match(/\[name="([^"]+)"\]/);
  return name ? fields.find(field => field.name === name[1] && (!selector.includes(':not') || field.type !== 'radio')) || null : null;
 },
 querySelectorAll(selector) {
  const name = selector.match(/^\[name="([^"]+)"\]$/);
  return name ? fields.filter(field => field.name === name[1]) : [];
 }
};
const document = { querySelector: selector => selector === '.mlab-smtp' ? root : null, querySelectorAll: () => [], addEventListener() {} };
class Event { constructor(type) { this.type = type; } }
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../assets/js/admin.js'), 'utf8'), { document, window: { mlabSmtp: { i18n: {} } }, Event });
function changePrimary(value) {
 for (const radio of radios) radio.checked = radio.value === value;
 radios.find(radio => radio.value === value).dispatchEvent(new Event('change'));
}
changePrimary('ssl');
assert.equal(primaryPort.value, '465');
assert.equal(backupPort.value, '587', 'primary changes must not touch the backup route');
assert.match(hints.smtp_secure.textContent, /465/);
changePrimary('tls');
assert.equal(primaryPort.value, '587');
changePrimary('none');
assert.equal(primaryPort.value, '25');
primaryPort.value = '2525';
changePrimary('ssl');
assert.equal(primaryPort.value, '2525', 'custom primary ports are preserved');
assert.match(hints.smtp_secure.textContent, /465/);
backup.value = 'ssl'; backup.dispatchEvent(new Event('change'));
assert.equal(backupPort.value, '465', 'backup selects get the same port logic as primary radios');
assert.equal(primaryPort.value, '2525');
backupPort.value = '2465'; backup.value = 'tls'; backup.dispatchEvent(new Event('change'));
assert.equal(backupPort.value, '2465', 'custom backup ports are preserved');
assert.match(hints.fb_smtp_secure.textContent, /587/);
backupPort.value = ''; backup.value = 'ssl'; backup.dispatchEvent(new Event('change'));
assert.equal(backupPort.value, '465', 'empty port gets a standard default');
backupPort.disabled = true; backup.value = 'tls'; backup.dispatchEvent(new Event('change'));
assert.equal(backupPort.value, '465', 'constant-locked ports are never changed');
backupPort.disabled = false; backupPort.readOnly = true; backup.value = 'none'; backup.dispatchEvent(new Event('change'));
assert.equal(backupPort.value, '465', 'read-only ports are never changed');
assert.ok(focused && scrolled, 'inline setup feedback is brought into view and focused');
console.log('PASS SMTP primary/backup ports, custom and locked ports, route isolation, live hints and feedback focus');
