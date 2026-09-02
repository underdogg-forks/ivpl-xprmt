/**
 * Browser coverage for application/modules/tasks/controllers/Ajax.php.
 * Mirrors tests/Feature/Projects/TasksAjaxControllerTest.php — the task lookup
 * modal used on invoice item rows. `$ajax_controller = true`.
 */

import { test, expect } from '../test.js';
import { createTask, uniq } from '../support/fixtures.js';

const XHR = { 'X-Requested-With': 'XMLHttpRequest' };

test.describe('Tasks AJAX', () => {
  test('it renders the task lookup modal with no invoice', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));

    const response = await page.request.post('/tasks/ajax/modal_task_lookups', { headers: XHR });

    expect(response.status()).toBe(200);
    const body = await response.text();
    expect(body).not.toMatch(/Fatal error|Uncaught|<b>Error<\/b>/i);
    expect(errors).toEqual([]);
  });

  test('it processes a task selection', async ({ page }) => {
    const task = await createTask(page, { task_name: uniq('LookupTaskMarker') });

    const response = await page.request.post('/tasks/ajax/process_task_selections', {
      headers: XHR,
      form: { 'task_ids[]': String(task.id) },
    });
    const json = await response.json();

    expect(json.map((row) => row.task_name)).toContain(task.name);
  });

  test('it returns an empty result when no task ids are selected', async ({ page }) => {
    await createTask(page, { task_name: uniq('NotSelectedTask') });

    const response = await page.request.post('/tasks/ajax/process_task_selections', { headers: XHR });

    expect(await response.json()).toEqual([]);
  });

  test('it requires an ajax request', async ({ page }) => {
    const response = await page.request.post('/tasks/ajax/process_task_selections');

    expect(response.status()).toBe(200);
    expect((await response.text()).trim()).toBe('');
  });
});
