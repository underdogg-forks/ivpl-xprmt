/**
 * Browser coverage for application/modules/tasks/controllers/Tasks.php.
 * Mirrors tests/Feature/Projects/TasksControllerTest.php.
 * Required fields (Mdl_Tasks::validation_rules): task_name, task_price,
 * task_finish_date.
 */

import { test, expect } from '../test.js';
import { createTask, uniq } from '../support/fixtures.js';
import { dbQuery } from '../support/db.js';
import { expectBlockedByRequired } from '../support/forms.js';

const FINISH = '2026-12-31';

async function fillRequired(page, { name, price = '100.00', finish = FINISH } = {}) {
  if (name !== undefined) await page.fill('#task_name', name);
  await page.fill('#task_price', price);
  await page.fill('#task_finish_date', finish);
}

test.describe('Tasks — list', () => {
  test('it lists every task', async ({ page }) => {
    const a = await createTask(page, { task_name: uniq('TaskAlpha') });
    const b = await createTask(page, { task_name: uniq('TaskBeta') });

    await page.goto('/tasks');

    await expect(page.getByRole('link', { name: a.name })).toBeVisible();
    await expect(page.getByRole('link', { name: b.name })).toBeVisible();
  });
});

test.describe('Tasks — create', () => {
  test('it creates a task', async ({ page }) => {
    const name = uniq('PayloadTask');

    await page.goto('/tasks/form');
    await fillRequired(page, { name });
    await Promise.all([page.waitForURL(/\/tasks(\/index)?$/), page.click('#btn-submit')]);

    await expect(page.getByRole('link', { name })).toBeVisible();
  });

  test('it fails to create without task_name', async ({ page }) => {
    await page.goto('/tasks/form');
    await fillRequired(page, {});
    await expectBlockedByRequired(page, '#task_name');
  });

  test('it fails to create without task_price', async ({ page }) => {
    await page.goto('/tasks/form');
    await page.fill('#task_name', uniq('NoPriceTask'));
    await page.fill('#task_finish_date', FINISH);
    await expectBlockedByRequired(page, '#task_price');
  });

  test('it fails to create without task_finish_date', async ({ page }) => {
    await page.goto('/tasks/form');
    await page.fill('#task_name', uniq('NoDateTask'));
    await page.fill('#task_price', '100.00');
    // The datepicker seeds today's date on a fresh form, so clear it first.
    await page.fill('#task_finish_date', '');
    await expectBlockedByRequired(page, '#task_finish_date');
  });
});

test.describe('Tasks — update', () => {
  test('it renders the edit form for the requested task only', async ({ page }) => {
    const target = await createTask(page, { task_name: uniq('EditableTask') });
    const other = await createTask(page, { task_name: uniq('OtherTask') });

    await page.goto(`/tasks/form/${target.id}`);

    await expect(page.locator('#task_name')).toHaveValue(target.name);
    await expect(page.locator('body')).not.toContainText(other.name);
  });

  test('it updates a task', async ({ page }) => {
    const task = await createTask(page, { task_name: uniq('OriginalTask') });
    const renamed = uniq('RenamedTask');

    await page.goto(`/tasks/form/${task.id}`);
    await fillRequired(page, { name: renamed });
    await Promise.all([page.waitForURL(/\/tasks(\/index)?$/), page.click('#btn-submit')]);

    await expect(page.getByRole('link', { name: renamed })).toBeVisible();
    await expect(page.getByRole('link', { name: task.name, exact: true })).toHaveCount(0);
  });

  test('it fails to update without task_name', async ({ page }) => {
    const task = await createTask(page, { task_name: uniq('KeepNameTask') });

    await page.goto(`/tasks/form/${task.id}`);
    await page.fill('#task_name', '');
    await expectBlockedByRequired(page, '#task_name');
  });

  test('it fails to update without task_price', async ({ page }) => {
    const task = await createTask(page, { task_name: uniq('KeepPriceTask') });

    await page.goto(`/tasks/form/${task.id}`);
    await page.fill('#task_price', '');
    await expectBlockedByRequired(page, '#task_price');
  });

  test('it fails to update without task_finish_date', async ({ page }) => {
    const task = await createTask(page, { task_name: uniq('KeepDateTask') });

    await page.goto(`/tasks/form/${task.id}`);
    await page.fill('#task_finish_date', '');
    await expectBlockedByRequired(page, '#task_finish_date');
  });
});

test.describe('Tasks — delete', () => {
  test('it deletes a task', async ({ page }) => {
    const doomed = await createTask(page, { task_name: uniq('DeletableTask') });
    const kept = await createTask(page, { task_name: uniq('KeptTask') });

    await page.goto('/tasks');
    const row = page.locator('tr', { has: page.getByRole('link', { name: doomed.name }) });
    await row.locator('.dropdown-toggle').click();
    page.once('dialog', (dialog) => dialog.accept());
    await Promise.all([page.waitForLoadState('load'), row.locator('button.dropdown-button').click()]);

    expect(dbQuery(`SELECT task_id FROM ip_tasks WHERE task_id = ${doomed.id}`)).toEqual([]);
    expect(dbQuery(`SELECT task_id FROM ip_tasks WHERE task_id = ${kept.id}`)).toHaveLength(1);
  });

  test('it still deletes a task when csrf protection is on and the token is valid', async () => {
    test.skip(true, 'needs a CSRF_PROTECTION=true server — see tests/E2E/README.md');
  });

  test('it does not delete a task when the csrf token is missing', async () => {
    test.skip(true, 'needs a CSRF_PROTECTION=true server — see tests/E2E/README.md');
  });
});

test.describe('Tasks — guest access', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('it redirects a guest to login and leaks no task', async ({ page }) => {
    const response = await page.goto('/tasks');

    await expect(page).toHaveURL(/\/sessions\/login/);
    expect(await response.text()).not.toContain('Secret Task');
  });
});
