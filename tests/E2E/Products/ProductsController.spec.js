/**
 * Browser coverage for application/modules/products/controllers/Products.php.
 * Mirrors tests/Feature/Products/ProductsControllerTest.php.
 * Required fields (Mdl_Products::validation_rules): product_name, product_price.
 */

import { test, expect } from '../test.js';
import { createProduct, uniq } from '../support/fixtures.js';
import { expectBlockedByRequired } from '../support/forms.js';

test.describe('Products — list', () => {
  test('it lists every product', async ({ page }) => {
    const a = await createProduct(page, { product_name: uniq('WidgetAlpha') });
    const b = await createProduct(page, { product_name: uniq('WidgetBeta') });

    await page.goto('/products');

    await expect(page.getByRole('link', { name: a.name })).toBeVisible();
    await expect(page.getByRole('link', { name: b.name })).toBeVisible();
  });
});

test.describe('Products — create', () => {
  test('it creates a product', async ({ page }) => {
    const name = uniq('DeluxeWidget');

    await page.goto('/products/form');
    await page.fill('#product_name', name);
    await page.fill('#product_price', '19.99');
    await Promise.all([page.waitForURL(/\/products(\/index)?$/), page.click('#btn-submit')]);

    await expect(page.getByRole('link', { name })).toBeVisible();
  });

  test('it fails to create without product_name', async ({ page }) => {
    await page.goto('/products/form');
    await page.fill('#product_price', '9.99');
    await expectBlockedByRequired(page, '#product_name');
  });

  test('it fails to create without product_price', async ({ page }) => {
    await page.goto('/products/form');
    await page.fill('#product_name', 'Priceless Widget');
    await expectBlockedByRequired(page, '#product_price');
  });
});

test.describe('Products — update', () => {
  test('it renders the edit form for the requested product only', async ({ page }) => {
    const target = await createProduct(page, { product_name: uniq('EditableWidget') });
    const other = await createProduct(page, { product_name: uniq('OtherWidget') });

    await page.goto(`/products/form/${target.id}`);

    await expect(page.locator('#product_name')).toHaveValue(target.name);
    await expect(page.locator('body')).not.toContainText(other.name);
  });

  test('it updates a product', async ({ page }) => {
    const product = await createProduct(page, { product_name: uniq('OriginalWidget'), product_price: '10.00' });
    const renamed = uniq('RenamedWidget');

    await page.goto(`/products/form/${product.id}`);
    await page.fill('#product_name', renamed);
    await page.fill('#product_price', '12.50');
    await Promise.all([page.waitForURL(/\/products(\/index)?$/), page.click('#btn-submit')]);

    await expect(page.getByRole('link', { name: renamed })).toBeVisible();
    await expect(page.getByRole('link', { name: product.name, exact: true })).toHaveCount(0);
  });

  test('it fails to update without product_name', async ({ page }) => {
    const product = await createProduct(page, { product_name: uniq('KeepThisWidget') });

    await page.goto(`/products/form/${product.id}`);
    await page.fill('#product_name', '');
    await expectBlockedByRequired(page, '#product_name');
  });

  test('it fails to update without product_price', async ({ page }) => {
    const product = await createProduct(page, { product_name: uniq('PriceKeptWidget'), product_price: '7.00' });

    await page.goto(`/products/form/${product.id}`);
    await page.fill('#product_price', '');
    await expectBlockedByRequired(page, '#product_price');
  });
});

test.describe('Products — delete', () => {
  test('it deletes a product', async ({ page }) => {
    const doomed = await createProduct(page, { product_name: uniq('DeletableWidget') });
    const kept = await createProduct(page, { product_name: uniq('KeptWidget') });

    await page.goto('/products');
    const row = page.locator('tr', { has: page.getByRole('link', { name: doomed.name }) });
    await row.locator('.dropdown-toggle').click();
    page.once('dialog', (dialog) => dialog.accept());
    await Promise.all([page.waitForLoadState('load'), row.locator('button.dropdown-button').click()]);

    await page.goto('/products');
    await expect(page.getByRole('link', { name: doomed.name })).toHaveCount(0);
    await expect(page.getByRole('link', { name: kept.name })).toBeVisible();
  });

  test('it still deletes a product when csrf protection is on and the token is valid', async () => {
    test.skip(true, 'needs a CSRF_PROTECTION=true server — see tests/E2E/README.md');
  });

  test('it does not delete a product when the csrf token is missing', async () => {
    test.skip(true, 'needs a CSRF_PROTECTION=true server — see tests/E2E/README.md');
  });
});

test.describe('Products — guest access', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('it redirects a guest to login and leaks no product', async ({ page }) => {
    const response = await page.goto('/products');

    await expect(page).toHaveURL(/\/sessions\/login/);
    expect(await response.text()).not.toContain('Secret Widget');
  });
});
