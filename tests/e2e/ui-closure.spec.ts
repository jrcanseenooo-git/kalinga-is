import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { expectNoHorizontalOverflow, login } from './helpers';

async function open(page:Page,kind:string) {
    await login(page); await page.goto(`/cases?search=SYN-CLOSURE-UI-${kind}`);
    await page.getByRole('link',{name:new RegExp(`SYN-CLOSURE-UI-${kind}`)}).first().click();
    await expect(page).toHaveURL(/\/cases\/\d+$/);
    await page.getByRole('tab',{name:/^Closure & aftercare/}).click();
    const panel=page.getByRole('region',{name:'Closure and aftercare workspace',exact:true});
    await expect(panel.getByRole('heading',{name:'Current closure record',exact:true})).toBeVisible();return panel;
}
async function submit(page:Page,text:string) {
    const panel=page.getByRole('region',{name:'Closure and aftercare workspace',exact:true});
    await panel.getByRole('textbox',{name:'Closure summary',exact:true}).fill(text);
    await panel.getByRole('button',{name:'Save closure recommendation',exact:true}).click();
    await page.getByRole('dialog').locator('#generic-submit-confirm').click();
}

test('real closure interface saves revisions, independently reviews, closes and reopens with retained history',async({page,browser})=>{
    const panel=await open(page,'WORKFLOW'); const url=page.url();
    await submit(page,'Synthetic first interface closure recommendation.');
    await expect(panel.getByRole('status')).toContainText('Closure record saved.');
    await submit(page,'Synthetic revised interface closure recommendation.');
    await expect(panel.getByRole('region',{name:'Current closure record',exact:true})).toContainText('Version 2');
    await panel.getByRole('button',{name:/^Version 1 -/}).click();
    await expect(panel.getByRole('article',{name:'Selected historical closure record',exact:true})).toContainText('Synthetic first interface closure recommendation.');
    const context=await browser.newContext();const reviewer=await context.newPage();
    try {
        await login(reviewer,{email:'assessment-reviewer@kalinga.test',password:'password'});await reviewer.goto(url);
        await reviewer.getByRole('tab',{name:/^Closure & aftercare/}).click();
        const reviewPanel=reviewer.getByRole('region',{name:'Closure and aftercare workspace',exact:true});
        reviewer.once('dialog',dialog=>dialog.accept());await reviewPanel.getByRole('button',{name:'Review saved closure record',exact:true}).click();
        await expect(reviewPanel.getByRole('status')).toContainText('Independent review recorded.');
        reviewer.once('dialog',dialog=>dialog.accept());await reviewPanel.getByRole('button',{name:'Close reviewed case',exact:true}).click();
        await expect(reviewer.getByRole('region',{name:'Case folder summary',exact:true})).toContainText('Closed');
        await page.reload();await page.getByRole('tab',{name:/^Closure & aftercare/}).click();
        page.once('dialog',dialog=>dialog.accept());await panel.getByRole('button',{name:'Reopen case',exact:true}).click();
        await expect(page.getByRole('region',{name:'Case folder summary',exact:true})).toContainText('Reopened');
        await expect(panel.getByRole('region',{name:'Current closure record',exact:true})).toContainText('No current closure record.');
        await panel.getByRole('button',{name:/^Version 2 -/}).click();await expect(panel.getByRole('article',{name:'Selected historical closure record',exact:true})).toContainText('Synthetic revised interface closure recommendation.');
        expect(await page.evaluate(()=>JSON.stringify(localStorage)+JSON.stringify(sessionStorage))).not.toContain('Synthetic revised interface closure recommendation.');
        expect(await page.evaluate(()=>JSON.stringify(history.state))).not.toContain('Synthetic revised interface closure recommendation.');
    } finally {await context.close();}
});

test('closure server validation retains unfinished text and focuses an accessible error summary',async({page})=>{
    const panel=await open(page,'VALIDATION');await panel.getByRole('textbox',{name:'Closure summary',exact:true}).evaluate(element=>element.setAttribute('maxlength','6000'));
    await submit(page,'S'.repeat(5001));await expect(panel.getByRole('alert').first()).toBeFocused();await expect(panel.getByText(/5000/)).toBeVisible();
    await expect(panel.getByRole('textbox',{name:'Closure summary',exact:true})).toHaveValue('S'.repeat(5001));
});

test('concurrent closure revision cannot overwrite a newer summary and requires explicit reconciliation',async({page,context})=>{
    const panel=await open(page,'STALE');const other=await context.newPage();await other.goto(page.url());await other.getByRole('tab',{name:/^Closure & aftercare/}).click();
    await expect(other.getByRole('textbox',{name:'Closure summary',exact:true})).toBeVisible();
    await panel.getByRole('textbox',{name:'Closure summary',exact:true}).fill('Synthetic retained stale draft.');
    await submit(other,'Synthetic newer saved revision.');await expect(other.getByRole('status').filter({hasText:'Closure record saved.'})).toBeVisible();
    await panel.getByRole('button',{name:'Save closure recommendation',exact:true}).click();await page.getByRole('dialog').locator('#generic-submit-confirm').click();
    await expect(panel.getByRole('alert')).toContainText('case changed');await expect(panel.getByRole('textbox',{name:'Closure summary',exact:true})).toHaveValue('Synthetic retained stale draft.');
    await panel.getByRole('button',{name:'Refresh closure records',exact:true}).click();await expect(panel.getByRole('region',{name:'Current closure record',exact:true})).toContainText('Synthetic newer saved revision.');
    await expect(panel.getByRole('button',{name:'Save closure recommendation',exact:true})).toBeDisabled();
    page.once('dialog',dialog=>dialog.accept());await panel.getByRole('button',{name:'Discard unfinished revision',exact:true}).click();await expect(panel.getByRole('textbox',{name:'Closure summary',exact:true})).toHaveValue('Synthetic newer saved revision.');
    await expect(panel.getByRole('region',{name:'Closure version history',exact:true})).toContainText('1 records');await other.close();
});

test('lost closure acknowledgement requires refresh and does not repeat a successful write',async({page})=>{
    const panel=await open(page,'LOSTACK');let writes=0;
    await page.route('**/cases/*/closure',async route=>{if(route.request().method()==='POST'){writes++;await route.fetch();await route.abort('failed');}else await route.continue();});
    await submit(page,'Synthetic saved closure with lost acknowledgement.');await expect(panel.getByRole('alert')).toContainText('outcome could not be confirmed');
    await expect(panel.getByRole('button',{name:'Save closure recommendation',exact:true})).toBeDisabled();await page.unroute('**/cases/*/closure');
    await panel.getByRole('button',{name:'Refresh closure records',exact:true}).click();await expect(panel.getByRole('region',{name:'Current closure record',exact:true})).toContainText('Synthetic saved closure with lost acknowledgement.');
    page.once('dialog',dialog=>dialog.accept());await panel.getByRole('button',{name:'Discard unfinished revision',exact:true}).click();expect(writes).toBe(1);
    await expect(panel.getByRole('region',{name:'Closure version history',exact:true})).toContainText('1 records');
});

test('acknowledged closure with failed readback blocks more writes and recovers without duplicate history',async({page})=>{
    const panel=await open(page,'READBACK');let fail=false;
    await page.route('**/cases/*/closure',async route=>{const response=await route.fetch();fail=true;await route.fulfill({response});});
    await page.route('**/cases/*/closure-records?*',route=>fail?route.fulfill({status:503,json:{message:'Synthetic readback unavailable.'}}):route.continue());
    await submit(page,'Synthetic acknowledged closure before readback failure.');await expect(panel.getByRole('alert')).toContainText('change was saved, but readback failed');
    await expect(panel.getByRole('button',{name:'Save closure recommendation',exact:true})).toBeDisabled();fail=false;
    await panel.getByRole('button',{name:'Refresh closure records',exact:true}).click();await expect(panel.getByRole('region',{name:'Current closure record',exact:true})).toContainText('Synthetic acknowledged closure before readback failure.');
    await expect(panel.getByRole('region',{name:'Closure version history',exact:true})).toContainText('1 records');
});

test('unfinished closure text survives section switching and cancelled navigation',async({page})=>{
    const panel=await open(page,'DENIAL');await panel.getByRole('textbox',{name:'Closure summary',exact:true}).fill('Synthetic unfinished closure retained in memory.');
    await page.getByRole('tab',{name:'Overview',exact:true}).click();await page.getByRole('tab',{name:/^Closure & aftercare/}).click();await expect(panel.getByRole('textbox',{name:'Closure summary',exact:true})).toHaveValue('Synthetic unfinished closure retained in memory.');
    let prompted=false;page.once('dialog',async dialog=>{prompted=true;await dialog.dismiss();});await page.getByRole('link',{name:'Back to case registry',exact:true}).click();await expect.poll(()=>prompted).toBe(true);await expect(panel.getByRole('textbox',{name:'Closure summary',exact:true})).toHaveValue('Synthetic unfinished closure retained in memory.');
});

test('confirmed closure access denial clears unfinished narratives before reauthorizing the page',async({page})=>{
    const panel=await open(page,'DENIAL');await panel.getByRole('textbox',{name:'Closure summary',exact:true}).fill('SYNTHETIC-UNFINISHED-DENIED-CLOSURE');
    await page.route('**/cases/*/closure-records?*',route=>route.fulfill({status:403,json:{message:'Synthetic denial.'}}),{times:1});
    const navigation=page.waitForEvent('framenavigated',frame=>frame===page.mainFrame());
    await panel.getByRole('button',{name:'Refresh closure records',exact:true}).click();await navigation;
    await expect(page.getByRole('region',{name:'Closure and aftercare workspace',exact:true})).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText('SYNTHETIC-UNFINISHED-DENIED-CLOSURE');
});

test('expired authenticated session clears the closure editor and returns to login',async({page,context})=>{
    const panel=await open(page,'DENIAL');await panel.getByRole('textbox',{name:'Closure summary',exact:true}).fill('SYNTHETIC-EXPIRED-CLOSURE-DRAFT');
    const other=await context.newPage();await other.goto('/dashboard');await other.evaluate(async()=>{await window.axios.post('/logout');});
    await panel.getByRole('button',{name:'Refresh closure records',exact:true}).click();await expect(page).toHaveURL(/\/login$/);
    await expect(page.locator('body')).not.toContainText('SYNTHETIC-EXPIRED-CLOSURE-DRAFT');await other.close();
});

for(const width of [360,390,768,1024,1440,1920])test(`closure interface responsive and selected history at ${width}px`,async({page})=>{
    await page.setViewportSize({width,height:1080});const panel=await open(page,'VISUAL');await panel.evaluate(element=>element.scrollIntoView({block:'start'}));
    await expect.poll(async()=>Math.round((await panel.boundingBox())!.y)).toBeGreaterThanOrEqual(64);
    await expectNoHorizontalOverflow(page);let scan=await new AxeBuilder({page}).include('main').analyze();expect(scan.violations.filter(item=>['serious','critical'].includes(item.impact??''))).toEqual([]);
    await page.screenshot({path:`docs/implementation/evidence/ui-13/current-${width}.png`,animations:'disabled'});
    await panel.getByRole('button',{name:/^Version 1 -/}).click();await expect(panel.getByRole('article',{name:'Selected historical closure record',exact:true})).toContainText('Synthetic earlier retained closure recommendation.');
    await panel.getByRole('region',{name:'Closure version history',exact:true}).evaluate(element=>element.scrollIntoView({block:'start'}));await expectNoHorizontalOverflow(page);scan=await new AxeBuilder({page}).include('main').analyze();expect(scan.violations.filter(item=>['serious','critical'].includes(item.impact??''))).toEqual([]);
    await page.screenshot({path:`docs/implementation/evidence/ui-13/history-${width}.png`,animations:'disabled'});
});

test('closure continuing-support link opens the real aftercare activity',async({page})=>{
    const panel=await open(page,'VISUAL');await panel.getByRole('link',{name:'Open aftercare & reintegration',exact:true}).click();
    await expect(page.getByRole('region',{name:'Aftercare and reintegration',exact:true})).toBeVisible();await expect(page.getByRole('heading',{name:'Aftercare continuity',exact:true})).toBeVisible();
});
