import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { login, expectNoHorizontalOverflow } from './helpers';

async function open(page:Page) {await login(page,{email:'cpu@kalinga.test',password:'password'});await page.goto('/cpu/coordination');}

test('Connect locks the composer and group switch while a send is awaiting acknowledgement',async({page})=>{
    await open(page);let release!:()=>void;const wait=new Promise<void>(resolve=>release=resolve);let held=false;
    await page.route('**/cpu/coordination/*/messages',async route=>{if(route.request().method()==='POST'){held=true;await wait;}await route.continue();});
    try {
        await page.getByLabel('New message',{exact:true}).fill('Synthetic held Connect send.');await page.getByRole('button',{name:'Send message',exact:true}).click();await page.getByRole('dialog').locator('#generic-submit-confirm').click();await expect.poll(()=>held).toBe(true);
        await expect(page.getByLabel('New message',{exact:true})).toBeDisabled();await expect(page.getByLabel('Coordination circle',{exact:true})).toBeDisabled();
    } finally {release();}
    await expect(page.getByRole('region',{name:'Coordination messages',exact:true})).toContainText('Synthetic held Connect send.');
});

test('Connect isolates unfinished messages and reply context by authorized circle',async({page})=>{
    await page.setViewportSize({width:1024,height:1080});await open(page);const select=page.getByLabel('Coordination circle',{exact:true});const first=await select.inputValue();
    await page.getByLabel('New message',{exact:true}).fill('Synthetic first circle unfinished text.');
    const second=await select.locator('option').filter({hasText:'Synthetic second coordination circle'}).getAttribute('value');await select.selectOption(second!);
    await expect(page.getByRole('region',{name:'Coordination messages',exact:true})).toContainText('Synthetic second-circle operational message.');await expect(page.getByLabel('New message',{exact:true})).toHaveValue('');
    await page.getByLabel('New message',{exact:true}).fill('Synthetic second circle unfinished text.');await select.selectOption(first);
    await expect(page.getByLabel('New message',{exact:true})).toHaveValue('Synthetic first circle unfinished text.');await select.selectOption(second!);await expect(page.getByLabel('New message',{exact:true})).toHaveValue('Synthetic second circle unfinished text.');
});

test('Connect retains a lost-acknowledgement message for explicit review without automatically resubmitting',async({page})=>{
    await open(page);let writes=0;const text='Synthetic Connect lost acknowledgement message.';
    await page.route('**/cpu/coordination/*/messages',async route=>{if(route.request().method()==='POST'){writes++;await route.fetch();await route.abort('failed');}else await route.continue();});
    await page.getByLabel('New message',{exact:true}).fill(text);await page.getByRole('button',{name:'Send message',exact:true}).click();await page.getByRole('dialog').locator('#generic-submit-confirm').click();
    await expect(page.getByRole('alert').first()).toContainText('send outcome could not be confirmed');await expect(page.getByRole('button',{name:'Send message',exact:true})).toBeDisabled();await expect(page.getByLabel('New message',{exact:true})).toHaveValue(text);
    await page.unroute('**/cpu/coordination/*/messages');await page.getByRole('button',{name:'Refresh conversation',exact:true}).click();await expect(page.getByRole('region',{name:'Coordination messages',exact:true})).toContainText(text);
    page.once('dialog',dialog=>dialog.accept());await page.getByRole('button',{name:'Discard unfinished message',exact:true}).click();expect(writes).toBe(1);
    await expect(page.getByRole('region',{name:'Coordination messages',exact:true}).getByRole('article').filter({hasText:text})).toHaveCount(1);
});

test('Connect acknowledged send with failed readback clears only the sent composer and blocks another write',async({page})=>{
    await open(page);let fail=false;
    await page.route('**/cpu/coordination/*/messages',async route=>{if(route.request().method()==='POST'){const response=await route.fetch();fail=true;await route.fulfill({response});}else if(fail)await route.fulfill({status:503,json:{message:'Synthetic readback failure.'}});else await route.continue();});
    await page.getByLabel('New message',{exact:true}).fill('Synthetic acknowledged Connect message before failed refresh.');await page.getByRole('button',{name:'Send message',exact:true}).click();await page.getByRole('dialog').locator('#generic-submit-confirm').click();
    await expect(page.getByRole('alert').first()).toContainText('Message sent, but readback failed');await expect(page.getByLabel('New message',{exact:true})).toHaveValue('');await expect(page.getByRole('button',{name:'Send message',exact:true})).toBeDisabled();fail=false;
    await page.getByRole('button',{name:'Refresh conversation',exact:true}).click();await expect(page.getByRole('region',{name:'Coordination messages',exact:true})).toContainText('Synthetic acknowledged Connect message before failed refresh.');
});

test('Connect validation retains text and focuses the error summary',async({page})=>{
    await open(page);await page.getByLabel('New message',{exact:true}).evaluate(element=>element.setAttribute('maxlength','4000'));await page.getByLabel('New message',{exact:true}).fill('S'.repeat(3001));await page.getByRole('button',{name:'Send message',exact:true}).click();await page.getByRole('dialog').locator('#generic-submit-confirm').click();await expect(page.getByRole('alert').first()).toBeFocused();await expect(page.getByLabel('New message',{exact:true})).toHaveValue('S'.repeat(3001));
});

test('Connect refresh retains unfinished reply metadata and cancelled navigation retains the draft',async({page})=>{
    await open(page);const region=page.getByRole('region',{name:'Coordination messages',exact:true});await region.getByRole('article').last().getByRole('button',{name:'Reply',exact:true}).click();await page.getByLabel('New message',{exact:true}).fill('Synthetic unfinished operational reply.');await page.getByRole('button',{name:'Refresh conversation',exact:true}).click();await expect(page.getByText('Replying to a message in this group',{exact:true})).toBeVisible();await expect(page.getByLabel('New message',{exact:true})).toHaveValue('Synthetic unfinished operational reply.');
    let prompted=false;page.once('dialog',async dialog=>{prompted=true;await dialog.dismiss();});await page.getByRole('link',{name:'My referred cases',exact:true}).first().click();await expect.poll(()=>prompted).toBe(true);await expect(page.getByLabel('New message',{exact:true})).toHaveValue('Synthetic unfinished operational reply.');
    const stored=await page.evaluate(()=>JSON.stringify(localStorage)+JSON.stringify(sessionStorage)+JSON.stringify(history.state));expect(stored).not.toContain('Synthetic unfinished operational reply.');
});

test('Connect chooses authorized references by case number for real chat tags',async({page})=>{
    await open(page);const form=page.getByRole('form',{name:'Send coordination message',exact:true});await form.locator('details').getByText('Case reference, urgency & mentions',{exact:true}).click();
    const select=form.getByRole('combobox',{name:'Case reference (optional)',exact:true});await expect.poll(()=>select.locator('option').count()).toBeGreaterThan(1);const option=select.locator('option').nth(1);const reference=await option.textContent();await select.selectOption((await option.getAttribute('value'))!);
    await page.getByLabel('New message',{exact:true}).fill('Synthetic selected case-reference coordination.');await page.getByRole('button',{name:'Send message',exact:true}).click();await page.getByRole('dialog').locator('#generic-submit-confirm').click();
    const article=page.getByRole('region',{name:'Coordination messages',exact:true}).getByRole('article').filter({hasText:'Synthetic selected case-reference coordination.'});await expect(article).toContainText(reference!.split(' - ')[0]);
});

test('capture reference lookup failure retains the professional summary and recovers through POST lookup',async({page})=>{
    await open(page);await page.route('**/cpu/coordination/*/case-references',route=>route.fulfill({status:503,json:{message:'Synthetic reference lookup unavailable.'}}));
    await page.getByRole('region',{name:'Coordination messages',exact:true}).getByRole('article').last().getByRole('button',{name:'Record as note',exact:true}).click();const dialog=page.getByRole('dialog');await dialog.getByLabel('Professional summary',{exact:true}).fill('Synthetic professional summary retained while reference lookup fails.');await expect(dialog.getByRole('alert')).toContainText('Case references could not be refreshed');await page.unroute('**/cpu/coordination/*/case-references');
    await dialog.getByText('Find or refresh case references',{exact:true}).click();const response=page.waitForResponse(response=>/\/case-references$/.test(new URL(response.url()).pathname));await dialog.getByRole('button',{name:'Find references',exact:true}).click();expect((await response).request().method()).toBe('POST');await expect.poll(()=>dialog.getByRole('combobox',{name:'Authorized case reference',exact:true}).locator('option').count()).toBeGreaterThan(1);await expect(dialog.getByLabel('Professional summary',{exact:true})).toHaveValue('Synthetic professional summary retained while reference lookup fails.');
});

test('case-reference access denial clears unfinished Connect work before reauthorization',async({page})=>{
    await open(page);await page.getByLabel('New message',{exact:true}).fill('SYNTHETIC-REFERENCE-DENIED-MESSAGE');await page.route('**/cpu/coordination/*/case-references',route=>route.fulfill({status:403,json:{message:'Synthetic reference access denied.'}}),{times:1});
    const navigation=page.waitForEvent('framenavigated',frame=>frame===page.mainFrame());await page.getByRole('region',{name:'Coordination messages',exact:true}).getByRole('article').last().getByRole('button',{name:'Record as note',exact:true}).click();await navigation;await expect(page.getByLabel('New message',{exact:true})).toHaveValue('');expect(await page.evaluate(()=>JSON.stringify(localStorage)+JSON.stringify(sessionStorage)+JSON.stringify(history.state))).not.toContain('SYNTHETIC-REFERENCE-DENIED-MESSAGE');
});

test('phone Write message shortcut focuses the real composer',async({page})=>{
    await page.setViewportSize({width:390,height:700});await open(page);await page.getByRole('button',{name:'Write message',exact:true}).click();await expect(page.getByLabel('New message',{exact:true})).toBeFocused();await expect(page.getByLabel('New message',{exact:true})).toBeInViewport();
});

for(const width of [360,390,768,1024,1440,1920])test(`Connect groups conversation and capture dialog at ${width}px`,async({page})=>{
    await page.setViewportSize({width,height:1080});await open(page);await expectNoHorizontalOverflow(page);let scan=await new AxeBuilder({page}).include('main').analyze();expect(scan.violations.filter(item=>['serious','critical'].includes(item.impact??''))).toEqual([]);
    await page.screenshot({path:`docs/implementation/evidence/ui-14/conversation-${width}.png`,animations:'disabled'});
    await page.getByRole('region',{name:'Coordination messages',exact:true}).getByRole('article').last().getByRole('button',{name:'Record as note',exact:true}).click();const dialog=page.getByRole('dialog');await expect(dialog).toBeVisible();await expect(dialog.getByRole('group',{name:'Case reference selection',exact:true})).toHaveAttribute('aria-busy','false');await expect.poll(()=>dialog.locator('form').evaluate(element=>getComputedStyle(element.parentElement!).opacity)).toBe('1');await expectNoHorizontalOverflow(page);scan=await new AxeBuilder({page}).include('dialog').analyze();expect(scan.violations.filter(item=>['serious','critical'].includes(item.impact??''))).toEqual([]);await page.screenshot({path:`docs/implementation/evidence/ui-14/capture-${width}.png`,animations:'disabled'});await dialog.getByRole('button',{name:'Cancel',exact:true}).click();
});
