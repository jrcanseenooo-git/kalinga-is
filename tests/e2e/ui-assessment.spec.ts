import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, expectNoHorizontalOverflow } from './helpers';

async function openAssessment(page:Page,number:string) {
 await page.goto(`/cases?search=${encodeURIComponent(number)}`);await page.getByRole('link',{name:new RegExp(number)}).first().click();await expect(page).toHaveURL(/\/cases\/\d+$/);
 await page.getByRole('tab',{name:/^Assessment/}).click();
 return page.locator('#case-panel-assessment');
}
async function confirmAction(page:Page) {
 await page.getByRole('dialog').getByRole('button',{name:'Confirm status change',exact:true}).click();
}

test('comprehensive assessment saves, recovers validation and requires independent review to finalize',async({page,browser})=>{
 await login(page);const panel=await openAssessment(page,'SYN-UI-2026-0003');const caseUrl=page.url();
 await panel.getByRole('button',{name:'Create initial draft',exact:true}).click();
 await page.getByRole('dialog').locator('#generic-submit-confirm').click();
 await expect(panel.getByRole('navigation',{name:'Assessment sections',exact:true})).toBeVisible();
 await panel.getByLabel('Verified facts',{exact:true}).fill('Synthetic confirmed assessment facts.');
 await panel.getByLabel('Source attribution',{exact:true}).selectOption('worker_observation');
 await panel.getByLabel('Section completion state',{exact:true}).selectOption('completed');
 await panel.getByRole('button',{name:'Submit for review',exact:true}).click();
 await expect(panel.getByRole('alert')).toContainText('Save your changed sections');
 let rejected=false;
 await page.route('**/comprehensive-assessments/*/sections',async route=>{
  if(!rejected){rejected=true;await route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({errors:{facts:['Synthetic field validation recovery.']}})});}else await route.continue();
 });
 await panel.getByRole('button',{name:'Save this section',exact:true}).click();
 await expect(panel.getByRole('alert').first()).toContainText('Synthetic field validation recovery.');
 await expect(panel.getByRole('alert').first()).toBeFocused();
 await expect(panel.getByLabel('Verified facts',{exact:true})).toHaveValue('Synthetic confirmed assessment facts.');
 const actualSave=page.waitForResponse(response=>response.request().method()==='PUT'&&/\/sections$/.test(new URL(response.url()).pathname));
 await panel.getByRole('button',{name:'Save this section',exact:true}).click();
 expect((await actualSave).status()).toBeLessThan(400);
 await expect(panel.getByRole('status').filter({hasText:'Assessment section saved.'})).toBeVisible();
 await page.unroute('**/comprehensive-assessments/*/sections');
 await page.reload();await page.getByRole('tab',{name:/^Assessment/}).click();
 await expect(panel.getByLabel('Verified facts',{exact:true})).toHaveValue('Synthetic confirmed assessment facts.');
 await panel.getByRole('button',{name:'Submit for review',exact:true}).click();await confirmAction(page);
 await expect(panel.getByRole('status').filter({hasText:'Assessment status updated.'})).toBeVisible();
 await expect(panel.getByRole('button',{name:'Save this section',exact:true})).toHaveCount(0);
 await expect(panel.getByRole('button',{name:'Finalize as reviewer',exact:true})).toHaveCount(0);
 const reviewerContext=await browser.newContext();const reviewer=await reviewerContext.newPage();
 try{
  await login(reviewer,{email:'assessment-reviewer@kalinga.test',password:'password'});await reviewer.goto(caseUrl);await reviewer.getByRole('tab',{name:/^Assessment/}).click();
  const reviewPanel=reviewer.locator('#case-panel-assessment');
  await reviewPanel.getByLabel('Reviewer comments',{exact:true}).fill('Synthetic reviewer requests source clarification.');
  await reviewPanel.getByRole('button',{name:'Return for revision',exact:true}).click();await confirmAction(reviewer);
  await expect(reviewPanel.getByRole('status').filter({hasText:'Assessment status updated.'})).toBeVisible();
  await page.reload();await page.getByRole('tab',{name:/^Assessment/}).click();
  await expect(panel.getByText('Synthetic reviewer requests source clarification.',{exact:true}).first()).toBeVisible();
  await panel.getByLabel('Verified facts',{exact:true}).fill('Synthetic clarified and verified assessment facts.');
  await panel.getByRole('button',{name:'Save this section',exact:true}).click();await expect(panel.getByRole('status').filter({hasText:'Assessment section saved.'})).toBeVisible();
  await panel.getByRole('button',{name:'Submit for review',exact:true}).click();await confirmAction(page);await expect(panel.getByRole('status').filter({hasText:'Assessment status updated.'})).toBeVisible();
  await reviewer.reload();await reviewer.getByRole('tab',{name:/^Assessment/}).click();
  await reviewPanel.getByRole('button',{name:'Mark as reviewed',exact:true}).click();await confirmAction(reviewer);
  await expect(reviewPanel.getByRole('button',{name:'Finalize as reviewer',exact:true})).toBeVisible();
  await reviewPanel.getByRole('button',{name:'Finalize as reviewer',exact:true}).click();await confirmAction(reviewer);
  await expect(reviewPanel.getByRole('status').filter({hasText:'Assessment status updated.'})).toBeVisible();
  await expect(reviewPanel.getByRole('button',{name:'Save this section',exact:true})).toHaveCount(0);
  await page.reload();await page.getByRole('tab',{name:/^Assessment/}).click();
  await expect(panel.getByText('Synthetic clarified and verified assessment facts.',{exact:true})).toBeVisible();
  await expect(panel.getByRole('button',{name:'Save this section',exact:true})).toHaveCount(0);
 }finally{await reviewerContext.close();}
});

for(const width of [360,390,768,1024,1440,1920])test(`comprehensive assessment responsive sections at ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:1080});await login(page);const panel=await openAssessment(page,'SYN-UI-2026-0004');
 await expect(panel.getByRole('navigation',{name:'Assessment sections',exact:true})).toBeVisible();
 await expect(panel.getByRole('navigation',{name:'Assessment sections',exact:true}).getByRole('button')).toHaveCount(11);
 await expect(panel.getByText('1 / 11 sections recorded as completed',{exact:true})).toBeVisible();
 await expect.poll(async()=>Math.round((await panel.boundingBox())!.y)).toBeGreaterThanOrEqual(64);
 await expect.poll(async()=>Math.round((await panel.boundingBox())!.y)).toBeLessThan(160);
 await expectNoHorizontalOverflow(page);
 const scan=await new AxeBuilder({page}).include('main').analyze();expect(scan.violations.filter(item=>['serious','critical'].includes(item.impact??''))).toEqual([]);
 await page.screenshot({path:`docs/implementation/evidence/ui-07/viewport-${width}.png`,animations:'disabled'});
 await page.screenshot({path:`docs/implementation/evidence/ui-07/assessment-${width}.png`,fullPage:true,animations:'disabled'});
 const controls=panel.getByRole('navigation',{name:'Assessment sections',exact:true});
 await controls.getByRole('button',{name:/^Health and SRH/}).click();await expect(panel.getByLabel('Verified facts',{exact:true})).toHaveValue('');
 await controls.getByRole('button',{name:/^Presenting situation/}).click();await expect(panel.getByLabel('Verified facts',{exact:true})).toHaveValue('Synthetic verified assessment facts for responsive QA.');
});
