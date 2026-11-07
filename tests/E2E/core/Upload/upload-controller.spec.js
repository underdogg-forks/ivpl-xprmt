/**
 * Browser coverage for application/modules/upload/controllers/Upload.php, mirroring
 * tests/Feature/Core/Upload/UploadControllerTest.php: the files attached to a client or document under a url key are
 * listed, served and deleted strictly by that key, the metadata of a vanished file is pruned, a traversal name cannot
 * leave the upload folder, and a request without a file is refused.
 */

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { test, expect } from '../../test.js';
import { dbInsert } from '../../support/db.js';
import { count } from '../../support/quotes.js';

const FILES = path.resolve('uploads/customer_files');
const XHR = { 'X-Requested-With': 'XMLHttpRequest' };
const created = [];

const urlKey = () => crypto.randomBytes(16).toString('hex');
const today = () => new Date().toISOString().slice(0, 10);

/** A file on disk plus its ip_uploads row, as the dropzone leaves it. */
function storeFile(key, name, content) {
  const file = path.join(FILES, `${key}_${name}`);
  fs.writeFileSync(file, content);
  created.push(file);
  dbInsert('ip_uploads', { client_id: 1, url_key: key, file_name_original: name, file_name_new: `${key}_${name}`, uploaded_date: today() });

  return file;
}

test.afterEach(() => {
  for (const file of created.splice(0)) fs.rmSync(file, { force: true });
});

test.describe('Upload — listing', () => {
  test('it lists the files stored under a url key', async ({ page }) => {
    /* Arrange */
    const key = urlKey();
    storeFile(key, 'contract.pdf', '%PDF-1.4 contract');

    /* Act */
    const response = await page.request.get(`/upload/show_files/${key}`);

    /* Assert */
    expect(response.status()).toBe(200);
    expect(await response.json()).toEqual([{ name: 'contract.pdf', size: '%PDF-1.4 contract'.length }]);
  });

  test('it does not list files stored under another url key', async ({ page }) => {
    /* Arrange */
    const ownKey = urlKey();
    const otherKey = urlKey();
    storeFile(ownKey, 'mine.pdf', '%PDF mine');
    storeFile(otherKey, 'secret.pdf', '%PDF secret');

    /* Act */
    const body = await (await page.request.get(`/upload/show_files/${ownKey}`)).text();

    /* Assert */
    expect(body).toContain('mine.pdf');
    expect(body).not.toContain('secret.pdf');
  });

  test('it prunes metadata for a file that no longer exists on disk', async ({ page }) => {
    /* Arrange */
    const key = urlKey();
    dbInsert('ip_uploads', { client_id: 1, url_key: key, file_name_original: 'ghost.pdf', file_name_new: `${key}_ghost.pdf`, uploaded_date: today() });

    /* Act */
    const body = await (await page.request.get(`/upload/show_files/${key}`)).text();

    /* Assert: nothing listed, and the stale row is cleaned up */
    expect(Object.keys(JSON.parse(body || '[]') ?? [])).toEqual([]);
    expect(count('ip_uploads', `url_key = '${key}' AND file_name_original = 'ghost.pdf'`)).toBe(0);
  });
});

test.describe('Upload — serving', () => {
  test('it serves an uploaded file by its url key and name', async ({ page }) => {
    /* Arrange */
    const key = urlKey();
    storeFile(key, 'invoice.pdf', '%PDF-1.4 exact-bytes');

    /* Act */
    const response = await page.request.get(`/upload/get_file/${encodeURIComponent(`${key}_invoice.pdf`)}`);

    /* Assert */
    expect(response.status()).toBe(200);
    expect(await response.text()).toBe('%PDF-1.4 exact-bytes');
  });

  test('it returns 404 for a file that does not exist', async ({ page }) => {
    /* Act */
    const response = await page.request.get(`/upload/get_file/${encodeURIComponent(`${urlKey()}_missing.pdf`)}`);

    /* Assert */
    expect(response.status()).toBe(404);
  });
});

test.describe('Upload — deleting', () => {
  test('it deletes the file and its metadata', async ({ page }) => {
    /* Arrange */
    const key = urlKey();
    const file = storeFile(key, 'old.pdf', '%PDF old');

    /* Act */
    const response = await page.request.post(`/upload/delete_file/${key}`, { headers: XHR, form: { name: 'old.pdf' } });

    /* Assert */
    expect(response.status()).toBe(200);
    expect(fs.existsSync(file)).toBe(false);
    expect(count('ip_uploads', `url_key = '${key}' AND file_name_original = 'old.pdf'`)).toBe(0);
  });

  test('it does not delete a file that belongs to another url key', async ({ page }) => {
    /* Arrange */
    const ownKey = urlKey();
    const otherKey = urlKey();
    const victim = storeFile(otherKey, 'keep.pdf', '%PDF keep');

    /* Act: the name matches the victim's file, under the wrong key */
    const response = await page.request.post(`/upload/delete_file/${ownKey}`, { headers: XHR, form: { name: 'keep.pdf' } });

    /* Assert */
    expect(fs.existsSync(victim)).toBe(true);
    expect(count('ip_uploads', `url_key = '${otherKey}' AND file_name_original = 'keep.pdf'`)).toBe(1);
    expect(response.status()).not.toBe(500);
  });

  test('it confines a traversal name on delete to the upload folder', async ({ page }) => {
    /* Arrange */
    const key = urlKey();
    const canary = path.resolve('ipconfig.php');
    const own = storeFile(key, 'ipconfig.php.pdf', '%PDF own');

    /* Act: basename() reduces the name to "ipconfig.php", which can only ever address "<key>_ipconfig.php" in the folder */
    const response = await page.request.post(`/upload/delete_file/${key}`, { headers: XHR, form: { name: '../../ipconfig.php' } });

    /* Assert: the real config is untouched, and so is an unrelated upload under the same key */
    expect(response.status()).toBe(200);
    expect(fs.existsSync(canary)).toBe(true);
    expect(fs.existsSync(own)).toBe(true);
  });
});

test.describe('Upload — requests', () => {
  test('it rejects an upload request that carries no file', async ({ page }) => {
    /* Arrange */
    const key = urlKey();

    /* Act */
    const response = await page.request.post(`/upload/upload_file/1/${key}`, { headers: XHR });

    /* Assert */
    expect(response.status()).toBe(400);
    expect(count('ip_uploads', `url_key = '${key}'`)).toBe(0);
  });

  test.describe('as a guest', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('it redirects a guest away from the file list', async ({ page }) => {
      /* Arrange */
      const key = urlKey();
      storeFile(key, 'private.pdf', '%PDF private');

      /* Act */
      const response = await page.goto(`/upload/show_files/${key}`);

      /* Assert */
      await expect(page).toHaveURL(/\/sessions\/login/);
      expect(await response.text()).not.toContain('private.pdf');
    });
  });
});
