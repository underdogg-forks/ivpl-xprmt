/**
 * Browser coverage for application/modules/units/controllers/Units.php.
 * Mirrors tests/Feature/Products/UnitsControllerTest.php.
 * Required fields (Mdl_Units::validation_rules): unit_name, unit_name_plrl.
 */

import { test, expect } from '../test.js';
import { createUnit, uniq } from '../support/fixtures.js';
import { expectBlockedByRequired, expectErrorFlash } from '../support/forms.js';

test.describe('Units — list', () => {
  test('it lists every unit', async ({ page }) => {
    const a = await createUnit(page, { unit_name: uniq('Hour') });
    const b = await createUnit(page, { unit_name: uniq('Kilogram') });

    await page.goto('/units');

    await expect(page.locator('#content')).toContainText(a.name);
    await expect(page.locator('#content')).toContainText(b.name);
  });
});

test.describe('Units — create', () => {
  test('it creates a unit', async ({ page }) => {
    const name = uniq('Litre');

    await page.goto('/units/form');
    await page.fill('#unit_name', name);
    await page.fill('#unit_name_plrl', `${name}s`);
    await Promise.all([page.waitForURL(/\/units(\/index)?$/), page.click('#btn-submit')]);

    await expect(page.locator('#content')).toContainText(name);
  });

  test('it fails to create without unit_name', async ({ page }) => {
    await page.goto('/units/form');
    await page.fill('#unit_name_plrl', 'Nameless Plural');
    await expectBlockedByRequired(page, '#unit_name');
  });

  test('it fails to create without unit_name_plrl', async ({ page }) => {
    await page.goto('/units/form');
    await page.fill('#unit_name', 'Singular Only');
    await expectBlockedByRequired(page, '#unit_name_plrl');
  });

  test('it rejects a duplicate unit name on create', async ({ page }) => {
    const name = uniq('DuplicateUnit');
    await createUnit(page, { unit_name: name });

    await page.goto('/units/form');
    await page.fill('#unit_name', name);
    await page.fill('#unit_name_plrl', 'Duplicate Units Again');
    await Promise.all([page.waitForLoadState('load'), page.click('#btn-submit')]);

    await expect(page).toHaveURL(/\/units\/form$/);
    await expectErrorFlash(page);
  });
});

test.describe('Units — update', () => {
  test('it renders the edit form for the requested unit only', async ({ page }) => {
    const target = await createUnit(page, { unit_name: uniq('EditableMetres') });
    const other = await createUnit(page, { unit_name: uniq('OtherMetre') });

    await page.goto(`/units/form/${target.id}`);

    await expect(page.locator('#unit_name')).toHaveValue(target.name);
    await expect(page.locator('body')).not.toContainText(other.name);
  });

  test('it updates a unit', async ({ page }) => {
    const unit = await createUnit(page, { unit_name: uniq('OriginalLitre') });
    const renamed = uniq('RenamedLitre');

    await page.goto(`/units/form/${unit.id}`);
    await page.fill('#unit_name', renamed);
    await page.fill('#unit_name_plrl', `${renamed}s`);
    await Promise.all([page.waitForURL(/\/units(\/index)?$/), page.click('#btn-submit')]);

    await expect(page.locator('#content')).toContainText(renamed);
    await expect(page.locator('#content')).not.toContainText(unit.name);
  });

  test('it fails to update without unit_name', async ({ page }) => {
    const unit = await createUnit(page, { unit_name: uniq('KeepThisUnit') });

    await page.goto(`/units/form/${unit.id}`);
    await page.fill('#unit_name', '');
    await expectBlockedByRequired(page, '#unit_name');
  });

  test('it fails to update without unit_name_plrl', async ({ page }) => {
    const unit = await createUnit(page, { unit_name: uniq('PluralKept') });

    await page.goto(`/units/form/${unit.id}`);
    await page.fill('#unit_name_plrl', '');
    await expectBlockedByRequired(page, '#unit_name_plrl');
  });
});

test.describe('Units — delete', () => {
  test('it deletes a unit', async ({ page }) => {
    const doomed = await createUnit(page, { unit_name: uniq('DeletableUnit') });
    const kept = await createUnit(page, { unit_name: uniq('KeptUnit') });

    await page.goto('/units');
    const row = page.locator('tr', { hasText: doomed.name });
    await row.locator('.dropdown-toggle').click();
    page.once('dialog', (dialog) => dialog.accept());
    await Promise.all([page.waitForLoadState('load'), row.locator('button.dropdown-button').click()]);

    await page.goto('/units');
    await expect(page.locator('#content')).not.toContainText(doomed.name);
    await expect(page.locator('#content')).toContainText(kept.name);
  });

  test('it still deletes a unit when csrf protection is on and the token is valid', async () => {
    test.skip(true, 'needs a CSRF_PROTECTION=true server — see tests/E2E/README.md');
  });

  test('it does not delete a unit when the csrf token is missing', async () => {
    test.skip(true, 'needs a CSRF_PROTECTION=true server — see tests/E2E/README.md');
  });
});

test.describe('Units — guest access', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('it redirects a guest to login and leaks no unit', async ({ page }) => {
    const response = await page.goto('/units');

    await expect(page).toHaveURL(/\/sessions\/login/);
    expect(await response.text()).not.toContain('Secret Unit');
  });
});
