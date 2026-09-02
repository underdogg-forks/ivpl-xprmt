/**
 * Browser coverage for application/modules/clients/controllers/Ajax.php.
 *
 * Mirrors tests/Feature/Clients/ClientsAjaxControllerTest.php. These endpoints
 * back the client-picker search box and the client-notes widget; every action
 * is guarded by `$ajax_controller = true`, so each request carries the
 * X-Requested-With header a real XHR would send (support/http.js `getJson`).
 * The requests reuse the page's authenticated admin session.
 */

import { test, expect } from '../test.js';
import { createClient, uniq } from '../support/fixtures.js';
import { getJson } from '../support/http.js';

test.describe('Clients AJAX — name_query', () => {
  test('it finds active clients matching the query', async ({ page }) => {
    const needle = await createClient(page, { client_name: uniq('Needle') });
    await createClient(page, { client_name: uniq('Haystack') });

    const { status, body } = await getJson(
      page,
      `/clients/ajax/name_query?query=${encodeURIComponent(needle.name)}`,
    );

    expect(status).toBe(200);
    expect(body.map((row) => row.text)).toContain(needle.name);
  });

  test('it excludes inactive clients from name_query', async ({ page }) => {
    const name = uniq('InactiveNeedle');
    await createClient(page, { client_name: name, client_active: '0' });

    const { body } = await getJson(
      page,
      `/clients/ajax/name_query?query=${encodeURIComponent(name)}`,
    );

    expect(body).toEqual([]);
  });

  test('it returns an empty result for name_query with no query', async ({ page }) => {
    await createClient(page, { client_name: uniq('AnyClient') });

    const { status, body } = await getJson(page, '/clients/ajax/name_query');

    expect(status).toBe(200);
    expect(body).toEqual([]);
  });

  test('it treats name_query input as a literal search term', async ({ page }) => {
    await createClient(page, { client_name: uniq('RealClient') });

    const { body } = await getJson(
      page,
      `/clients/ajax/name_query?query=${encodeURIComponent("x' OR '1'='1")}`,
    );

    expect(body).toEqual([]);
  });
});

test.describe('Clients AJAX — get_latest', () => {
  test('it returns up to five latest active clients', async ({ page }) => {
    for (let i = 0; i < 7; i++) {
      await createClient(page, { client_name: uniq(`Latest${i}`) });
    }

    const { status, body } = await getJson(page, '/clients/ajax/get_latest');

    expect(status).toBe(200);
    expect(body.length).toBe(5);
  });

  test('it escapes client names returned by get_latest', async ({ page }) => {
    await createClient(page, { client_name: '<script>alert(1)</script>' });

    const response = await page.request.get('/clients/ajax/get_latest', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    const raw = await response.text();

    // Two layers must hold: the form's input sanitiser never lets a live
    // <script> reach storage, and get_latest htmlsc()-escapes on the way out —
    // so the payload can never round-trip as executable markup. (The PHPUnit
    // test seeds the raw string straight into the DB and asserts the exact
    // entity-encoded form; through the real form the stored value is already
    // neutralised, so here we assert the invariant, not the byte sequence.)
    expect(raw.toLowerCase()).not.toContain('<script');
    const text = JSON.parse(raw).map((row) => row.text)[0];
    expect(text).not.toMatch(/<[a-z]/i);
  });
});

test.describe('Clients AJAX — permissive-search preference', () => {
  // The saved preference surfaces as the value of #input_permissive_search_clients,
  // the hidden field the client picker reads (rendered on e.g. the project form).
  test('it saves a valid permissive search preference', async ({ page }) => {
    const response = await page.request.get(
      '/clients/ajax/save_preference_permissive_search_clients?permissive_search_clients=1',
      { headers: { 'X-Requested-With': 'XMLHttpRequest' } },
    );

    expect(response.status()).toBe(200);
    await page.goto('/projects/form');
    await expect(page.locator('#input_permissive_search_clients')).toHaveValue('1');
  });

  test('it rejects an invalid permissive search preference value', async ({ page }) => {
    const response = await page.request.get(
      '/clients/ajax/save_preference_permissive_search_clients?permissive_search_clients=2',
      { headers: { 'X-Requested-With': 'XMLHttpRequest' } },
    );

    expect(response.status()).toBe(200);
    await page.goto('/projects/form');
    await expect(page.locator('#input_permissive_search_clients')).toHaveValue('');
  });
});

test.describe('Clients AJAX — client notes', () => {
  test('it saves a client note with all required fields', async ({ page }) => {
    const client = await createClient(page);

    const response = await page.request.post('/clients/ajax/save_client_note', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      form: { client_id: String(client.id), client_note: 'A note about this client' },
    });
    const json = await response.json();

    expect(json.success).toBe(1);
    const notes = await page.request.post('/clients/ajax/load_client_notes', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      form: { client_id: String(client.id) },
    });
    expect(await notes.text()).toContain('A note about this client');
  });

  test('it fails to save a client note without client_id', async ({ page }) => {
    const response = await page.request.post('/clients/ajax/save_client_note', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      form: { client_id: '', client_note: 'Orphan note' },
    });

    expect((await response.json()).success).toBe(0);
  });

  test('it fails to save a client note without client_note text', async ({ page }) => {
    const client = await createClient(page);

    const response = await page.request.post('/clients/ajax/save_client_note', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      form: { client_id: String(client.id), client_note: '' },
    });

    expect((await response.json()).success).toBe(0);
  });

  test('it deletes an existing client note', async ({ page }) => {
    const client = await createClient(page);
    await page.request.post('/clients/ajax/save_client_note', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      form: { client_id: String(client.id), client_note: 'Note to delete' },
    });
    const listBefore = await (
      await page.request.post('/clients/ajax/load_client_notes', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        form: { client_id: String(client.id) },
      })
    ).text();
    // partial_notes.php renders <span data-id="{client_note_id}" class="delete_client_note …">
    const noteId = listBefore.match(/data-id="(\d+)"/)?.[1];
    expect(noteId, 'the saved note id should be discoverable in the notes partial').toBeTruthy();

    const response = await page.request.post('/clients/ajax/delete_client_note', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      form: { client_note_id: String(noteId) },
    });

    expect((await response.json()).success).toBe(1);
    const listAfter = await (
      await page.request.post('/clients/ajax/load_client_notes', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        form: { client_id: String(client.id) },
      })
    ).text();
    expect(listAfter).not.toContain('Note to delete');
  });

  test('it does not delete anything for a nonexistent note id', async ({ page }) => {
    const client = await createClient(page);
    await page.request.post('/clients/ajax/save_client_note', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      form: { client_id: String(client.id), client_note: 'Untouched note' },
    });

    const response = await page.request.post('/clients/ajax/delete_client_note', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      form: { client_note_id: '999999' },
    });

    expect((await response.json()).success).toBe(0);
    const list = await (
      await page.request.post('/clients/ajax/load_client_notes', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        form: { client_id: String(client.id) },
      })
    ).text();
    expect(list).toContain('Untouched note');
  });

  test('it loads notes for a client', async ({ page }) => {
    const client = await createClient(page);
    await page.request.post('/clients/ajax/save_client_note', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      form: { client_id: String(client.id), client_note: 'Visible note marker' },
    });

    const response = await page.request.post('/clients/ajax/load_client_notes', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      form: { client_id: String(client.id) },
    });

    expect(response.status()).toBe(200);
    expect(await response.text()).toContain('Visible note marker');
  });
});

test.describe('Clients AJAX — guard', () => {
  test('it requires an ajax request', async ({ page }) => {
    await createClient(page, { client_name: uniq('ShouldNotAppear') });

    // No X-Requested-With header: Base_Controller's guard is a bare exit —
    // 200 with an empty body.
    const response = await page.request.get('/clients/ajax/get_latest');

    expect(response.status()).toBe(200);
    expect((await response.text()).trim()).toBe('');
  });
});
