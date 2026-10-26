import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, expectNoHorizontalOverflow } from './helpers';

async function openParticipation(page:Page,number='SYN-UI-2026-0005') {
 await login(page);await page.goto(`/cases?search=${encodeURIComponent(number)}`);await page.getByRole('link',{name:new RegExp(number)}).first().click();await expect(page).toHaveURL(/\/cases\/\d+$/);
 await page.getByRole('tab',{name:/^Assessment/}).click();const panel=page.locator('#case-panel-participation');
 await panel.getByRole('button',{name:'Load records',exact:true}).click();
 if(number==='SYN-UI-2026-0005'){await panel.getByRole('navigation',{name:'Participation records',exact:true}).getByRole('button',{name:/^Synthetic education participation occasion/}).click();await expect(panel.getByRole('region',{name:'Selected participation record',exact:true}).getByRole('heading',{name:'Synthetic education participation occasion',exact:true})).toBeVisible();}
 return panel;
}

test('child participation supports another distinct occasion after an existing record',async({page})=>{
 const panel=await openParticipation(page);
 const countBefore=await panel.getByRole('navigation',{name:'Participation records',exact:true}).getByRole('button').count();
 await expect(panel.getByRole('button',{name:'New decision or occasion',exact:true})).toBeVisible();
 await panel.getByRole('button',{name:'New decision or occasion',exact:true}).click();
 await panel.getByLabel(/Decision or participation activity/).selectOption('__other__');await panel.getByLabel('Specify other',{exact:true}).fill('Synthetic separate education decision');
 await panel.getByLabel('Activity type',{exact:true}).selectOption('education');
 await panel.getByLabel('Participation status',{exact:true}).selectOption('child_declined');
 await panel.getByLabel(/Neutral reason for participation status/).fill('Synthetic neutral record of the child declining this occasion.');
 await panel.getByRole('button',{name:'Create participation draft',exact:true}).click();
 await page.getByRole('dialog').locator('#generic-submit-confirm').click();
 await expect(panel.getByRole('status').filter({hasText:'Child participation draft created.'})).toBeVisible();
 const records=panel.getByRole('navigation',{name:'Participation records',exact:true});
 await expect(records.getByRole('button')).toHaveCount(countBefore+1);
 await expect(panel.getByRole('region',{name:'Selected participation record',exact:true})).toContainText('Synthetic separate education decision');
 await records.getByRole('button',{name:/^Synthetic education participation occasion/}).click();
 await panel.getByRole('navigation',{name:'Child participation sections',exact:true}).getByRole('button',{name:/^child views/i}).click();
 await expect(panel.getByLabel("Child's expressed views, wishes or concerns",{exact:true})).toHaveValue('Synthetic paraphrased child view.');
});

test('child participation saves distinct sources and supports independent return review and finalization',async({page,browser})=>{
 const panel=await openParticipation(page,'SYN-UI-2026-0006');const caseUrl=page.url();
 await panel.getByLabel(/Decision or participation activity/).selectOption('__other__');await panel.getByLabel('Specify other',{exact:true}).fill('Synthetic formal participation review');
 await panel.getByRole('button',{name:'Create participation draft',exact:true}).click();await page.getByRole('dialog').locator('#generic-submit-confirm').click();
 await expect(panel.getByRole('status').filter({hasText:'Child participation draft created.'})).toBeVisible();
 await panel.getByLabel('Preferred language',{exact:true}).fill('English');
 await panel.getByLabel('Reasonable privacy provided',{exact:true}).selectOption({label:'No'});
 await panel.getByLabel('Child appeared comfortable to continue',{exact:true}).selectOption({label:'Yes'});
 await panel.getByRole('button',{name:'Save participation section',exact:true}).click();await expect(panel.getByRole('status').filter({hasText:'Participation section saved.'})).toBeVisible();
 await page.reload();await page.getByRole('tab',{name:/^Assessment/}).click();
 await expect(panel.getByLabel('Reasonable privacy provided',{exact:true}).locator('option:checked')).toHaveText('No');
 await expect(panel.getByLabel('Child appeared comfortable to continue',{exact:true}).locator('option:checked')).toHaveText('Yes');
 const nav=panel.getByRole('navigation',{name:'Child participation sections',exact:true});await nav.getByRole('button',{name:/^child views/i}).click();
 await panel.getByLabel("Child's expressed views, wishes or concerns",{exact:true}).fill('Synthetic paraphrased view for review.');
 await panel.getByLabel('Direct quotation',{exact:true}).fill('Synthetic illustrative direct quote.');
 await panel.getByLabel('Worker observation',{exact:true}).fill('Synthetic separate worker observation.');
 await panel.getByLabel('Information reported by another person',{exact:true}).fill('Synthetic separately reported information.');
 let rejected=false;await page.route('**/child-participation/*/sections',async route=>{if(!rejected){rejected=true;await route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({errors:{child_views:['Synthetic participation validation recovery.']}})});}else await route.continue();});
 await panel.getByRole('button',{name:'Save participation section',exact:true}).click();await expect(panel.getByRole('alert').first()).toContainText('Synthetic participation validation recovery.');await expect(panel.getByRole('alert').first()).toBeFocused();
 await expect(panel.getByLabel('Direct quotation',{exact:true})).toHaveValue('Synthetic illustrative direct quote.');
 await panel.getByRole('button',{name:'Save participation section',exact:true}).click();await expect(panel.getByRole('status').filter({hasText:'Participation section saved.'})).toBeVisible();await page.unroute('**/child-participation/*/sections');
 await panel.getByRole('button',{name:'Submit for review',exact:true}).click();await page.getByRole('dialog').getByRole('button',{name:'Confirm status change',exact:true}).click();await expect(panel.getByRole('status').filter({hasText:'Participation record status updated.'})).toBeVisible();
 await expect(panel.getByRole('button',{name:'Finalize as reviewer',exact:true})).toHaveCount(0);
 const context=await browser.newContext({viewport:{width:1440,height:1080}});const reviewer=await context.newPage();
 try{
  await login(reviewer,{email:'assessment-reviewer@kalinga.test',password:'password'});await reviewer.goto(caseUrl);await reviewer.getByRole('tab',{name:/^Assessment/}).click();const review=reviewer.locator('#case-panel-participation');
  await review.getByRole('button',{name:'Return for revision',exact:true}).click();await reviewer.getByRole('dialog').getByRole('button',{name:'Confirm status change',exact:true}).click();await expect(review.getByRole('alert').first()).toContainText('A reviewer comment is required');
  // The confirmation dialog keeps the page inert until its close transition ends.
  await expect(reviewer.getByRole('dialog')).toHaveCount(0);
  await review.getByLabel('Reviewer comments',{exact:true}).fill('Synthetic participation reviewer requests clarification.');
  await review.getByRole('button',{name:'Return for revision',exact:true}).click();await reviewer.getByRole('dialog').getByRole('button',{name:'Confirm status change',exact:true}).click();await expect(review.getByRole('status').filter({hasText:'Participation record status updated.'})).toBeVisible();
  await page.reload();await page.getByRole('tab',{name:/^Assessment/}).click();await expect(panel.getByText('Synthetic participation reviewer requests clarification.',{exact:true}).first()).toBeVisible();
  await nav.getByRole('button',{name:/^child views/i}).click();await panel.getByLabel("Child's expressed views, wishes or concerns",{exact:true}).fill('Synthetic clarified child view.');
  await panel.getByRole('button',{name:'Save participation section',exact:true}).click();await expect(panel.getByRole('status').filter({hasText:'Participation section saved.'})).toBeVisible();
  await panel.getByRole('button',{name:'Submit for review',exact:true}).click();await page.getByRole('dialog').getByRole('button',{name:'Confirm status change',exact:true}).click();await expect(panel.getByRole('status').filter({hasText:'Participation record status updated.'})).toBeVisible();
  await reviewer.reload();await reviewer.getByRole('tab',{name:/^Assessment/}).click();await review.getByRole('button',{name:'Mark as reviewed',exact:true}).click();await reviewer.getByRole('dialog').getByRole('button',{name:'Confirm status change',exact:true}).click();
  await expect(review.getByRole('button',{name:'Finalize as reviewer',exact:true})).toBeVisible();await review.getByRole('button',{name:'Finalize as reviewer',exact:true}).click();await reviewer.getByRole('dialog').getByRole('button',{name:'Confirm status change',exact:true}).click();await expect(review.getByRole('status').filter({hasText:'Participation record status updated.'})).toBeVisible();
  await expect(review.getByRole('button',{name:'Save participation section',exact:true})).toHaveCount(0);
  await page.reload();await page.getByRole('tab',{name:/^Assessment/}).click();await nav.getByRole('button',{name:/^child views/i}).click();await expect(panel.getByText('Synthetic clarified child view.',{exact:true})).toBeVisible();
  await expect(panel.getByText('Synthetic illustrative direct quote.',{exact:true})).toBeVisible();await expect(panel.getByText('Synthetic separate worker observation.',{exact:true})).toBeVisible();
  expect(await page.evaluate(()=>JSON.stringify(localStorage)+JSON.stringify(sessionStorage))).not.toContain('Synthetic clarified child view.');
 }finally{await context.close();}
});

const sectionLabels=['Participation context','Child views and statements','Communication and accommodations','Decision under consideration','How views were considered','Best-interest considerations','Feedback to the child','Safeguards and follow-up'];
for(const width of [360,390,768,1024,1440,1920])test(`child participation responsive eight-section review at ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:1080});const panel=await openParticipation(page);const nav=panel.getByRole('navigation',{name:'Child participation sections',exact:true});
 await expect(nav.getByRole('button')).toHaveCount(8);
 await panel.scrollIntoViewIfNeeded();await expect(panel.getByRole('heading',{name:'Child participation',exact:true})).toBeInViewport();
 await page.screenshot({path:`docs/implementation/evidence/ui-08/viewport-${width}.png`,animations:'disabled'});
 for(let index=0;index<sectionLabels.length;index++){
  await nav.getByRole('button',{name:sectionLabels[index],exact:true}).click();const form=panel.locator('form[data-skip-generic-confirm="true"]');await expect(form.locator('legend')).toHaveText(sectionLabels[index]);
  await form.evaluate(element=>element.scrollIntoView({block:'start'}));await expectNoHorizontalOverflow(page);
  const scan=await new AxeBuilder({page}).include('main').analyze();expect(scan.violations.filter(item=>['serious','critical'].includes(item.impact??''))).toEqual([]);
  await page.screenshot({path:`docs/implementation/evidence/ui-08/section-${index+1}-${width}.png`,animations:'disabled'});
 }
 await page.screenshot({path:`docs/implementation/evidence/ui-08/participation-${width}.png`,fullPage:true,animations:'disabled'});
});

test('child participation section navigation retains unfinished context',async({page})=>{
 const panel=await openParticipation(page);const sections=panel.getByRole('navigation',{name:'Child participation sections',exact:true});
 await expect(sections).toBeVisible();await panel.getByRole('textbox').first().fill('Synthetic unfinished participation context.');
 await sections.getByRole('button',{name:/^child views/i}).click();await sections.getByRole('button',{name:/^participation context/i}).click();
 await expect(panel.getByRole('textbox').first()).toHaveValue('Synthetic unfinished participation context.');
});

test('child participation correction and addendum stay attached to their selected finalized occasion',async({page})=>{
 const panel=await openParticipation(page);const records=panel.getByRole('navigation',{name:'Participation records',exact:true});
 const sourceResponse=page.waitForResponse(response=>response.request().method()==='GET'&&/\/child-participation\/\d+$/.test(new URL(response.url()).pathname));
 await records.getByRole('button',{name:/^Synthetic finalized participation source/}).click();
 const source=(await(await sourceResponse).json()).record;
 await panel.getByRole('button',{name:'Correct or supplement this occasion',exact:true}).click();
 await expect(panel.getByLabel(/Decision or participation activity/)).toHaveValue('Synthetic finalized participation source');
 await expect(panel.getByLabel(/Decision or participation activity/)).toHaveJSProperty('readOnly',true);
 await expect(panel.getByLabel('Activity type',{exact:true})).toBeDisabled();
 await panel.getByLabel(/Reason for correction or addendum/).fill('Synthetic same-occasion correction.');
 // Changing the record being viewed must not change the correction source.
 await records.getByRole('button',{name:/^Synthetic education participation occasion/}).click();
 const request=page.waitForRequest(item=>item.method()==='POST'&&/\/child-participation$/.test(new URL(item.url()).pathname));
 await panel.getByRole('button',{name:'Create participation draft',exact:true}).click();await page.getByRole('dialog').locator('#generic-submit-confirm').click();
 const sent=(await request).postDataJSON();expect(sent.previous_record_id).toBe(source.id);expect(sent.decision_title).toBe(source.decision_title);
 await expect(panel.getByRole('status').filter({hasText:'Child participation draft created.'})).toBeVisible();
 await expect(panel.getByRole('region',{name:'Selected participation record',exact:true})).toContainText('Version 2 - full revision');
 await panel.getByRole('navigation',{name:'Child participation sections',exact:true}).getByRole('button',{name:/^child views/i}).click();
 await expect(panel.getByLabel("Child's expressed views, wishes or concerns",{exact:true})).toHaveValue('Synthetic finalized source child view.');
 await records.getByRole('button',{name:/^Synthetic finalized participation source/}).filter({hasText:'version 1 - final'}).click();
 await expect(panel.getByRole('button',{name:'Save participation section',exact:true})).toHaveCount(0);
 await expect(panel.getByText('Synthetic finalized source child view.',{exact:true})).toBeVisible();
 await panel.getByRole('button',{name:'Correct or supplement this occasion',exact:true}).click();
 await panel.getByLabel('Correction type',{exact:true}).selectOption('addendum');await panel.getByLabel(/Reason for correction or addendum/).fill('Synthetic separate supplementary record.');
 const addendum=page.waitForRequest(item=>item.method()==='POST'&&/\/child-participation$/.test(new URL(item.url()).pathname));
 await panel.getByRole('button',{name:'Create participation draft',exact:true}).click();await page.getByRole('dialog').locator('#generic-submit-confirm').click();
 expect((await addendum).postDataJSON().previous_record_id).toBe(source.id);
 await expect(panel.getByRole('status').filter({hasText:'Child participation draft created.'})).toBeVisible();
 await expect(panel.getByRole('region',{name:'Selected participation record',exact:true})).toContainText('Version 3 - addendum');
 await expect(panel.getByLabel("Child's expressed views, wishes or concerns",{exact:true})).toHaveValue('');
});

test('child participation hides an editor after confirmed case access is removed',async({page,browser})=>{
 const panel=await openParticipation(page,'SYN-EDITOR-REVOKE-2026-0001');const caseUrl=page.url();const casePath=new URL(caseUrl).pathname;
 await panel.getByRole('navigation',{name:'Child participation sections',exact:true}).getByRole('button',{name:/^child views/i}).click();
 const input=panel.getByLabel("Child's expressed views, wishes or concerns",{exact:true});await input.fill('Synthetic unsubmitted view before case access removal.');
 const context=await browser.newContext();const reviewer=await context.newPage();
 try{
  await login(reviewer,{email:'assessment-reviewer@kalinga.test',password:'password'});await reviewer.goto(caseUrl);
  await reviewer.getByRole('combobox',{name:'Assign case worker',exact:true}).selectOption({label:'Synthetic eligible recipient'});
  await reviewer.getByRole('button',{name:'Update assignment',exact:true}).click();await reviewer.getByRole('dialog').locator('#generic-submit-confirm').click();await expect(reviewer.getByRole('status').filter({hasText:'Case assignment updated.'})).toBeVisible();
  const key=await page.evaluate(()=>sessionStorage.getItem('historyKey'));
  const reauthorization=page.waitForResponse(response=>response.request().method()==='GET'&&new URL(response.url()).pathname===casePath,{timeout:10_000}).catch(()=>null);
  const rejected=page.waitForResponse(response=>response.request().method()==='PUT'&&/\/child-participation\/\d+\/sections$/.test(new URL(response.url()).pathname));
  await panel.getByRole('button',{name:'Save participation section',exact:true}).click();expect((await rejected).status()).toBe(403);
  await expect(input).toHaveCount(0);expect((await reauthorization)?.status()).toBe(403);
  await expect(page.getByRole('heading',{name:'You do not have access to this page',exact:true})).toBeVisible();
  expect(await page.evaluate(()=>sessionStorage.getItem('historyKey'))).not.toBe(key);
 }finally{await context.close();}
});

test('child participation can cancel native browser back without losing unfinished work',async({page})=>{
 const panel=await openParticipation(page);const caseUrl=page.url();
 await panel.getByRole('navigation',{name:'Child participation sections',exact:true}).getByRole('button',{name:/^child views/i}).click();
 const input=panel.getByLabel("Child's expressed views, wishes or concerns",{exact:true});await input.fill('Synthetic unfinished view retained after cancelling Back.');
 page.once('dialog',dialog=>dialog.dismiss());await page.goBack();
 await expect(page).toHaveURL(caseUrl);await expect(input).toHaveValue('Synthetic unfinished view retained after cancelling Back.');
});
