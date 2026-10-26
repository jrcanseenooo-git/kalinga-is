import { expect, test, type Page } from '@playwright/test';
import { login, expectNoHorizontalOverflow } from './helpers';
import AxeBuilder from '@axe-core/playwright';
import type { PlanningResponse } from '../../resources/js/types/planning';

async function selectServicePlan(page:Page,version=1){
 await expect(page.getByRole('navigation',{name:'Plan versions',exact:true})).toBeVisible();
 const status=version===1?'active':'draft';const picker=page.getByRole('combobox',{name:'Plan version',exact:true});if(await picker.isVisible())await picker.selectOption({label:`Version ${version} - ${status}`});else await page.getByRole('navigation',{name:'Plan versions',exact:true}).getByRole('button',{name:new RegExp(`^Version ${version} · ${status}`)}).click();
 const nav=page.getByRole('navigation',{name:'Planning activities',exact:true});const activity=nav.getByRole('combobox',{name:'Planning activity',exact:true});if(await activity.isVisible())await activity.selectOption({label:'Interventions'});else await nav.getByRole('button',{name:'Interventions',exact:true}).click();
}
async function openServices(page:Page){
 await login(page);await page.goto('/cases?search=SYN-SERVICE-2026-0001');await page.getByRole('link',{name:/SYN-SERVICE-2026-0001/}).first().click();await expect(page).toHaveURL(/\/cases\/\d+$/);const id=Number(new URL(page.url()).pathname.split('/')[2]);await page.goto(page.url().replace(/\/cases\/(\d+).*/,'/cases/$1/plan-workspace'));await selectServicePlan(page);return id;
}
async function patchAsBrowser(page:Page,url:string,body:Record<string,string>){return page.evaluate(async({url,body})=>{try{return(await window.axios.patch(url,body)).status;}catch(cause){return(cause as {response?:{status:number}}).response?.status??0;}},{url,body});}
test('first service write after sign-in works through normal Inertia navigation',async({page})=>{
 await login(page);const search=page.getByRole('searchbox',{name:'Search authorized cases',exact:true});await search.fill('SYN-SERVICE-2026-0001');await search.press('Enter');await page.getByRole('link',{name:/SYN-SERVICE-2026-0001/}).first().click();await page.goto(page.url().replace(/\/cases\/(\d+).*/,'/cases/$1/plan-workspace'));await selectServicePlan(page);
 const card=page.getByRole('article').filter({has:page.getByRole('heading',{name:'Synthetic linked education activity',exact:true})});await card.getByRole('button',{name:'Record service outcome',exact:true}).click();await card.getByRole('textbox',{name:'Actual outcome',exact:true}).fill('Synthetic first-write service outcome.');
 const write=page.waitForResponse(response=>response.request().method()==='PATCH'&&/\/interventions\/\d+$/.test(new URL(response.url()).pathname));await card.getByRole('button',{name:'Save service outcome',exact:true}).click();await page.getByRole('dialog').locator('#generic-submit-confirm').click();expect((await write).status()).toBe(204);
});
test('service outcome is editable while linked task execution remains authoritative',async({page})=>{
 await openServices(page);const card=page.getByRole('article').filter({has:page.getByRole('heading',{name:'Synthetic linked education activity',exact:true})});
 await expect(card.getByRole('button',{name:'Record service outcome',exact:true})).toBeVisible();await card.getByRole('button',{name:'Record service outcome',exact:true}).click();const form=card.getByRole('form');await expect(form.getByRole('combobox',{name:'Execution progress',exact:true})).toHaveCount(0);
 await form.getByRole('combobox',{name:'Outcome status',exact:true}).selectOption('partially_achieved');await form.getByRole('textbox',{name:'Actual outcome',exact:true}).fill('Synthetic recorded partial service outcome.');await form.getByRole('textbox',{name:'Barriers',exact:true}).fill('Synthetic separately recorded barrier.');await form.getByRole('textbox',{name:'Next action',exact:true}).fill('Synthetic professional next action.');
 await form.getByRole('button',{name:'Save service outcome',exact:true}).click();await page.getByRole('dialog').locator('#generic-submit-confirm').click();await expect(page.getByRole('status').filter({hasText:'Service outcome recorded.'})).toBeVisible();
 await page.reload();await selectServicePlan(page);await expect(card).toContainText('Execution status: open');await expect(card).toContainText('Synthetic recorded partial service outcome.');await expect(card).toContainText('Synthetic separately recorded barrier.');
 expect(await page.evaluate(()=>JSON.stringify(localStorage)+JSON.stringify(sessionStorage))).not.toContain('Synthetic recorded partial service outcome.');
});

test('unfinished service outcome survives version switching and blocks a read-only workflow change',async({page})=>{
 await openServices(page);const card=page.getByRole('article').filter({has:page.getByRole('heading',{name:'Synthetic linked education activity',exact:true})});await card.getByRole('button',{name:'Record service outcome',exact:true}).click();await card.getByRole('textbox',{name:'Actual outcome',exact:true}).fill('Synthetic unfinished service outcome.');
 const versions=page.getByRole('navigation',{name:'Plan versions',exact:true});await versions.getByRole('button',{name:/^Version 2 · draft/}).click();await expect(page.getByRole('button',{name:'Record service outcome',exact:true})).toHaveCount(0);await versions.getByRole('button',{name:/^Version 1 · active/}).click();await expect(card.getByRole('textbox',{name:'Actual outcome',exact:true})).toHaveValue('Synthetic unfinished service outcome.');
 await page.getByRole('navigation',{name:'Planning activities',exact:true}).getByRole('button',{name:'Review & history',exact:true}).click();await page.getByRole('button',{name:'Request formal review',exact:true}).click();await expect(page.getByRole('alert').first()).toContainText('Save unfinished work');await expect(page.getByRole('dialog')).toHaveCount(0);
});

test('historical service records retain a meaningful heading when no activity was recorded',async({page})=>{
 await openServices(page);await expect(page.getByRole('heading',{name:'Education intervention',exact:true})).toBeVisible();await expect(page.getByText('Synthetic historical service goal.',{exact:true})).toBeVisible();
});

test('case planning retains its current Cases navigation and breadcrumb context',async({page})=>{
 await openServices(page);await expect(page.getByRole('navigation',{name:'Workspace sections',exact:true}).getByRole('link',{name:'Cases',exact:true})).toHaveAttribute('aria-current','page');await expect(page.getByRole('navigation',{name:'Breadcrumb',exact:true})).toContainText('Cases - Case plan');
});

test('new planned service selects a real available provider and survives reload',async({page})=>{
 await openServices(page);await selectServicePlan(page,2);const form=page.getByRole('form',{name:'Plan intervention',exact:true});await form.getByRole('combobox',{name:'Select goal',exact:true}).selectOption({label:'Synthetic draft service goal.'});await form.getByRole('textbox',{name:'Agreed activity',exact:true}).fill('Synthetic newly planned provider activity');await form.getByRole('combobox',{name:'Service category',exact:true}).selectOption('__other__');await form.getByRole('textbox',{name:'Specify other',exact:true}).fill('Education');await form.getByRole('combobox',{name:'Service provider',exact:true}).selectOption({label:'Synthetic service partner'});await form.getByRole('button',{name:'Save intervention',exact:true}).click();await page.getByRole('dialog').locator('#generic-submit-confirm').click();
 const card=page.getByRole('article').filter({has:page.getByRole('heading',{name:'Synthetic newly planned provider activity',exact:true})});await expect(card).toContainText('Synthetic service partner');await page.reload();await selectServicePlan(page,2);await expect(card).toContainText('Synthetic service partner');await expect(card.getByRole('button',{name:'Record service outcome',exact:true})).toHaveCount(0);
});

test('unlinked service progress and outcome remain separate with recoverable validation',async({page})=>{
 await openServices(page);const card=page.getByRole('article').filter({has:page.getByRole('heading',{name:'Synthetic unlinked education activity',exact:true})});await card.getByRole('button',{name:'Record service outcome',exact:true}).click();const form=card.getByRole('form');await form.getByRole('combobox',{name:'Execution progress',exact:true}).selectOption('completed');await form.getByRole('textbox',{name:'Actual outcome',exact:true}).fill('Synthetic observed delivery outcome.');
 let rejected=false;await page.route('**/plans/*/interventions/*',async route=>{if(!rejected){rejected=true;await route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({errors:{actual_outcome:['Synthetic outcome validation recovery.']}})});}else await route.continue();});
 await form.getByRole('button',{name:'Save service outcome',exact:true}).click();await page.getByRole('dialog').locator('#generic-submit-confirm').click();await expect(page.getByRole('alert').first()).toContainText('Synthetic outcome validation recovery.');await expect(page.getByRole('alert').first()).toBeFocused();await expect(form.getByRole('textbox',{name:'Actual outcome',exact:true})).toHaveValue('Synthetic observed delivery outcome.');
 await form.getByRole('button',{name:'Save service outcome',exact:true}).click();await page.getByRole('dialog').locator('#generic-submit-confirm').click();await expect(card).toContainText('Synthetic observed delivery outcome.');await page.unroute('**/plans/*/interventions/*');await page.reload();await selectServicePlan(page);await expect(card).toContainText('completed');await expect(card).toContainText('Plan outcome: Not yet recorded');
});

test('service endpoint denies draft outcomes other authors and linked progress changes',async({page,browser})=>{
 const id=await openServices(page);const response=await page.request.get(`/cases/${id}/plans`);expect(response.headers()['cache-control']).toContain('no-store');const data=await response.json() as PlanningResponse;const active=data.plans.find(entry=>entry.plan.version===1)!;const draft=data.plans.find(entry=>entry.plan.version===2)!;const linked=active.interventions.find(action=>action.operational_record_type==='task')!;
 const url=`/cases/${id}/plans/${active.plan.id}/interventions/${linked.id}`;
 expect((await page.request.patch(url,{data:{outcome_status:'achieved'},headers:{Accept:'application/json','X-CSRF-TOKEN':'synthetic-invalid-token'}})).status()).toBe(419);
 expect(await patchAsBrowser(page,url,{progress:'completed'})).toBe(422);expect(await patchAsBrowser(page,`/cases/${id}/plans/${draft.plan.id}/interventions/${draft.interventions[0].id}`,{outcome_status:'achieved'})).toBe(403);
 const context=await browser.newContext();try{const reviewer=await context.newPage();await login(reviewer,{email:'assessment-reviewer@kalinga.test',password:'password'});expect(await patchAsBrowser(reviewer,url,{outcome_status:'achieved'})).toBe(403);}finally{await context.close();}
 const after=await(await page.request.get(`/cases/${id}/plans`)).json() as PlanningResponse;expect(after.plans.find(entry=>entry.plan.id===active.plan.id)!.interventions.find(action=>action.id===linked.id)!.operational_status).toBe('open');
});

for(const width of [360,390,768,1024,1440,1920])test(`intervention service responsive review at ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:1080});await openServices(page);const panel=page.getByRole('region',{name:'Interventions',exact:true});await panel.evaluate(element=>element.scrollIntoView({block:'start'}));await expectNoHorizontalOverflow(page);await expect(panel.getByRole('heading',{name:'Synthetic linked education activity',exact:true})).toBeVisible();await expect(panel.getByText('Past recorded target',{exact:true})).toBeVisible();
 await page.screenshot({path:`docs/implementation/evidence/ui-10/viewport-${width}.png`,animations:'disabled'});await panel.getByRole('searchbox',{name:"Search this plan's services",exact:true}).fill('unlinked');await expect(panel.getByRole('heading',{name:'Synthetic linked education activity',exact:true})).toHaveCount(0);await expect(panel.getByRole('heading',{name:'Synthetic unlinked education activity',exact:true})).toBeVisible();await panel.getByRole('button',{name:'Clear filters',exact:true}).click();
 const card=panel.getByRole('article').filter({has:page.getByRole('heading',{name:'Synthetic linked education activity',exact:true})});await card.getByRole('button',{name:'Record service outcome',exact:true}).click();await card.getByRole('form').evaluate(element=>element.scrollIntoView({block:'start'}));await expectNoHorizontalOverflow(page);await expect(card.getByRole('combobox',{name:'Execution progress',exact:true})).toHaveCount(0);const scan=await new AxeBuilder({page}).include('main').analyze();expect(scan.violations.filter(item=>['serious','critical'].includes(item.impact??''))).toEqual([]);await page.screenshot({path:`docs/implementation/evidence/ui-10/outcome-${width}.png`,animations:'disabled'});
});
