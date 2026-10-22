import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, expectNoHorizontalOverflow } from './helpers';

async function openPlanning(page:Page, number='SYN-UI-2026-0004') {
 await login(page);await page.goto(`/cases?search=${encodeURIComponent(number)}`);await page.getByRole('link',{name:new RegExp(number)}).first().click();await expect(page).toHaveURL(/\/cases\/\d+$/);
 await page.getByRole('link',{name:/Plan & continuity/}).first().click();await expect(page.getByRole('navigation',{name:'Plan versions',exact:true})).toBeVisible();if(!await page.getByRole('heading',{name:'Start a plan cycle',exact:true}).isVisible())await page.getByRole('button',{name:'Start a new cycle',exact:true}).click();await expect(page.getByRole('heading',{name:'Start a plan cycle',exact:true})).toBeVisible();
}
async function confirm(page:Page){await page.getByRole('dialog').locator('#generic-submit-confirm').click();}
async function createDraft(page:Page,objective:string){
 await page.getByRole('textbox',{name:'Overall objective',exact:true}).fill(objective);const ack=page.waitForResponse(response=>response.request().method()==='POST'&&/\/plans$/.test(new URL(response.url()).pathname));
 await page.getByRole('button',{name:'Create draft',exact:true}).click();await confirm(page);expect((await ack).status()).toBe(201);await expect(page.getByRole('status').filter({hasText:'Working on this planning workspace...'})).toHaveCount(0);
}

test('planning unfinished objective survives cancelling navigation',async({page})=>{
 await openPlanning(page);const url=page.url();await page.getByRole('textbox',{name:'Overall objective',exact:true}).fill('Synthetic unfinished planning work.');
 let prompted=false;page.once('dialog',async dialog=>{prompted=true;await dialog.dismiss();});await page.getByRole('link',{name:'Back to case',exact:true}).click();await expect.poll(()=>prompted).toBe(true);
 await expect(page).toHaveURL(url);await expect(page.getByRole('textbox',{name:'Overall objective',exact:true})).toHaveValue('Synthetic unfinished planning work.');
});

test('planning creation blocks a repeated submission while its acknowledgement is pending',async({page})=>{
 await openPlanning(page);let release!:()=>void;const held=new Promise<void>(resolve=>release=resolve);let posts=0;
 await page.route('**/cases/*/plans',async route=>{if(route.request().method()==='POST'){posts++;await held;}await route.continue();});
 await page.getByRole('textbox',{name:'Overall objective',exact:true}).fill('Synthetic single-flight plan.');const button=page.getByRole('button',{name:'Create draft',exact:true});
 try{await button.click();await confirm(page);await expect.poll(()=>posts).toBe(1);await expect(button).toBeDisabled();
  await button.locator('..').evaluate(element=>element.closest('form')!.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));await confirm(page);expect(posts).toBe(1);
 }finally{release();}
 await expect(page.getByRole('heading',{name:/Version \d+ [·-] draft/,exact:true})).toBeVisible();
});

test('planning validation focuses recoverable field errors without losing entered work',async({page})=>{
 await openPlanning(page);await page.route('**/cases/*/plans',async route=>route.request().method()==='POST'?route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({errors:{overall_objective:['Synthetic objective validation failure.']}})}):route.continue());
 await page.getByRole('textbox',{name:'Overall objective',exact:true}).fill('Synthetic retained objective.');await page.getByRole('button',{name:'Create draft',exact:true}).click();await confirm(page);
 await expect(page.getByRole('alert').first()).toContainText('Synthetic objective validation failure.');await expect(page.getByRole('alert').first()).toBeFocused();
 await expect(page.getByRole('textbox',{name:'Overall objective',exact:true})).toHaveValue('Synthetic retained objective.');
});

for(const status of [503,422])test(`planning acknowledged creation refresh ${status} requires review without another POST`,async({page})=>{
 await openPlanning(page);let acknowledged=false;let posts=0;
 await page.route('**/cases/*/plans',async route=>{
  if(route.request().method()==='POST'){posts++;const response=await route.fetch();acknowledged=true;await route.fulfill({response});}
  else if(acknowledged)await route.fulfill({status,body:'Synthetic interrupted refresh'});else await route.continue();
 });
 await page.getByRole('textbox',{name:'Overall objective',exact:true}).fill('Synthetic acknowledged plan refresh recovery.');await page.getByRole('button',{name:'Create draft',exact:true}).click();await confirm(page);
 await expect(page.getByRole('alert').first()).toContainText('The change was saved');await expect(page.getByRole('button',{name:'Create draft',exact:true})).toBeDisabled();expect(posts).toBe(1);
 await page.unroute('**/cases/*/plans');await page.getByRole('button',{name:'Refresh',exact:true}).click();await expect(page.getByRole('article').filter({has:page.getByRole('heading',{name:'Overall objective',exact:true})})).toContainText('Synthetic acknowledged plan refresh recovery.');expect(posts).toBe(1);
});

test('planning unfinished goals stay isolated when changing plan versions',async({page})=>{
 await openPlanning(page);await createDraft(page,'Synthetic first isolated planning cycle.');
 const first=await page.getByRole('heading',{name:/^Version \d+/}).innerText();await page.getByRole('form',{name:'Add goal',exact:true}).getByRole('textbox',{name:'Goal statement',exact:true}).fill('Synthetic unfinished goal for first plan only.');
 await page.getByRole('button',{name:'Start a new cycle',exact:true}).click();await createDraft(page,'Synthetic second isolated planning cycle.');
 await expect(page.getByRole('form',{name:'Add goal',exact:true}).getByRole('textbox',{name:'Goal statement',exact:true})).toHaveValue('');
 await page.getByRole('navigation',{name:'Plan versions',exact:true}).getByRole('button',{name:new RegExp(`^${first.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')}`)}).click();
 await expect(page.getByRole('form',{name:'Add goal',exact:true}).getByRole('textbox',{name:'Goal statement',exact:true})).toHaveValue('Synthetic unfinished goal for first plan only.');
 await page.getByRole('navigation',{name:'Planning activities',exact:true}).getByRole('button',{name:'Review & history',exact:true}).click();await page.getByRole('button',{name:'Submit for review',exact:true}).click();await expect(page.getByRole('alert').first()).toContainText('Save unfinished work');await expect(page.getByRole('alert').first()).toBeFocused();await expect(page.getByRole('dialog')).toHaveCount(0);
 expect(await page.evaluate(()=>JSON.stringify(localStorage)+JSON.stringify(sessionStorage))).not.toContain('Synthetic unfinished goal');
});

test('planning preserves independent return activation review and continuity workflows',async({page,browser})=>{
 await openPlanning(page,'SYN-UI-2026-0005');const url=page.url();await page.getByRole('textbox',{name:'Overall objective',exact:true}).fill('Synthetic agreed education and protection plan.');await page.getByRole('button',{name:'Create draft',exact:true}).click();await confirm(page);
 const goal=page.getByRole('form',{name:'Add goal',exact:true});await goal.getByLabel('Domain',{exact:true}).selectOption('education');await goal.getByRole('textbox',{name:'Need / problem',exact:true}).fill('Synthetic attendance barrier.');await goal.getByRole('textbox',{name:'Goal statement',exact:true}).fill('Synthetic sustainable education goal.');await goal.getByRole('button',{name:'Save goal',exact:true}).click();await confirm(page);await expect(goal.getByRole('textbox',{name:'Goal statement',exact:true})).toHaveValue('');
 const nav=page.getByRole('navigation',{name:'Planning activities',exact:true});await nav.getByRole('button',{name:'Interventions',exact:true}).click();const intervention=page.getByRole('form',{name:'Plan intervention',exact:true});
 await intervention.getByRole('combobox',{name:'Select goal',exact:true}).selectOption({label:'Synthetic sustainable education goal.'});await intervention.getByRole('textbox',{name:'Agreed activity',exact:true}).fill('Synthetic coordinate education support.');await intervention.getByRole('combobox',{name:'Service category',exact:true}).selectOption('__other__');await intervention.getByRole('textbox',{name:'Specify other',exact:true}).fill('Education');await intervention.getByRole('button',{name:'Save intervention',exact:true}).click();await confirm(page);await expect(intervention.getByRole('textbox',{name:'Agreed activity',exact:true})).toHaveValue('');
 await nav.getByRole('button',{name:'Review & history',exact:true}).click();await page.getByRole('button',{name:'Submit for review',exact:true}).click();await page.getByRole('dialog').getByRole('button',{name:'Confirm status change',exact:true}).click();await expect(page.getByRole('heading',{name:'Version 1 · for review',exact:true})).toBeVisible();
 const context=await browser.newContext();const reviewer=await context.newPage();try{
  await login(reviewer,{email:'assessment-reviewer@kalinga.test',password:'password'});await reviewer.goto(url);const reviewNav=reviewer.getByRole('navigation',{name:'Planning activities',exact:true});await reviewNav.getByRole('button',{name:'Review & history',exact:true}).click();
  await reviewer.getByLabel('Reviewer comments',{exact:true}).fill('Synthetic clarify planned support.');await reviewer.getByRole('button',{name:'Return for revision',exact:true}).click();await reviewer.getByRole('dialog').getByRole('button',{name:'Confirm status change',exact:true}).click();await expect(reviewer.getByRole('heading',{name:'Version 1 · returned',exact:true})).toBeVisible();
  await page.getByRole('button',{name:'Refresh',exact:true}).click();await expect(page.getByText('Synthetic clarify planned support.',{exact:true})).toBeVisible();await page.getByRole('button',{name:'Revise and resubmit',exact:true}).click();await page.getByRole('dialog').getByRole('button',{name:'Confirm status change',exact:true}).click();await expect(page.getByRole('heading',{name:'Version 1 · for review',exact:true})).toBeVisible();
  await reviewer.getByRole('button',{name:'Refresh',exact:true}).click();await reviewer.getByRole('button',{name:'Activate plan',exact:true}).click();await reviewer.getByRole('dialog').getByRole('button',{name:'Confirm status change',exact:true}).click();await expect(reviewer.getByRole('heading',{name:'Version 1 · active',exact:true})).toBeVisible();
  await page.getByRole('button',{name:'Refresh',exact:true}).click();await page.getByRole('button',{name:'Request formal review',exact:true}).click();await page.getByRole('dialog').getByRole('button',{name:'Confirm status change',exact:true}).click();await expect(page.getByRole('heading',{name:'Version 1 · under review',exact:true})).toBeVisible();
  await reviewer.getByRole('button',{name:'Refresh',exact:true}).click();const form=reviewer.getByRole('form',{name:'Record formal review',exact:true});await form.getByLabel('Professional recommendation',{exact:true}).fill('Synthetic documented continuation recommendation.');await form.getByRole('button',{name:'Finalize review',exact:true}).click();await confirm(reviewer);await expect(reviewer.getByRole('heading',{name:'Version 1 · active',exact:true})).toBeVisible();
  await nav.getByRole('button',{name:'Aftercare & reintegration',exact:true}).click();const aftercare=page.getByRole('form',{name:'Aftercare continuity',exact:true});await expect(aftercare.getByRole('combobox',{name:'Linked aftercare plan',exact:true}).locator('option:checked')).toHaveText('No linked plan');await aftercare.getByLabel('Aftercare required',{exact:true}).check();await aftercare.getByLabel('Responsible office',{exact:true}).selectOption('__other__');await aftercare.getByLabel('Specify other',{exact:true}).fill('Synthetic continuity office');await aftercare.getByRole('button',{name:'Save aftercare',exact:true}).click();await confirm(page);await expect(page.getByRole('status').filter({hasText:'Aftercare continuity record saved.'})).toBeVisible();
  const reintegration=page.getByRole('form',{name:'Reintegration',exact:true});await reintegration.getByRole('button',{name:'Save reintegration',exact:true}).click();await confirm(page);await expect(page.getByRole('status').filter({hasText:'Reintegration record saved.'})).toBeVisible();await page.reload();await page.getByRole('navigation',{name:'Planning activities',exact:true}).getByRole('button',{name:'Aftercare & reintegration',exact:true}).click();await expect(page.getByText('Synthetic continuity office - follow-up Not set',{exact:true})).toBeVisible();
 }finally{await context.close();}
});

for(const width of [360,390,768,1024,1440,1920])test(`planning task-focused responsive review at ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:1080});await openPlanning(page,'SYN-UI-2026-0006');const versions=page.getByRole('navigation',{name:'Plan versions',exact:true});
 if(await versions.getByRole('button').count()===0&&!await page.getByRole('heading',{name:'Version 1 · draft',exact:true}).count()){await page.getByRole('textbox',{name:'Overall objective',exact:true}).fill('Synthetic responsive helping-plan objective.');await page.getByRole('button',{name:'Create draft',exact:true}).click();await confirm(page);await expect(page.getByRole('heading',{name:'Version 1 · draft',exact:true})).toBeVisible();const goal=page.getByRole('form',{name:'Add goal',exact:true});await goal.getByRole('textbox',{name:'Need / problem',exact:true}).fill('Synthetic recorded support need.');await goal.getByRole('textbox',{name:'Goal statement',exact:true}).fill('Synthetic measurable education support goal.');await goal.getByRole('button',{name:'Save goal',exact:true}).click();await confirm(page);await expect(goal.getByRole('textbox',{name:'Goal statement',exact:true})).toHaveValue('');}
 else await page.getByRole('button',{name:'Hide new cycle',exact:true}).click();
 await expect(page.getByRole('status').filter({hasText:'Working on this planning workspace...'})).toHaveCount(0);await page.evaluate(()=>scrollTo(0,0));await page.screenshot({path:`docs/implementation/evidence/ui-09/viewport-${width}.png`,animations:'disabled'});
 const nav=page.getByRole('navigation',{name:'Planning activities',exact:true});for(const name of ['Goals & objectives','Interventions','Review & history','Aftercare & reintegration','Formal handover']){
  if(width<640)await nav.getByRole('combobox',{name:'Planning activity',exact:true}).selectOption({label:name});else await nav.getByRole('button',{name,exact:true}).click();const region={ 'Goals & objectives':'Goals and objectives','Interventions':'Interventions','Review & history':'Workflow and formal review','Aftercare & reintegration':'Aftercare and reintegration','Formal handover':'Formal handover'}[name]!;await page.getByRole('region',{name:region,exact:true}).evaluate(element=>element.scrollIntoView({block:'start'}));await expectNoHorizontalOverflow(page);const scan=await new AxeBuilder({page}).include('main').analyze();expect(scan.violations.filter(item=>['serious','critical'].includes(item.impact??''))).toEqual([]);await page.screenshot({path:`docs/implementation/evidence/ui-09/${name.split(' ')[0].toLowerCase()}-${width}.png`,animations:'disabled'});
 }
});
