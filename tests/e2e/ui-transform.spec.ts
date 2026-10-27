import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, expectNoHorizontalOverflow } from './helpers';


/** Hand a case over through the formal handover in the plan workspace (the only way a case changes case manager). */
async function completeHandover(page:Page,caseUrl:string,objective:string){
 await page.goto(caseUrl.replace(/\/cases\/(\d+).*/,'/cases/$1/plan-workspace'));
 await page.getByRole('textbox',{name:'Overall objective',exact:true}).fill(objective);await page.getByRole('button',{name:'Create draft',exact:true}).click();await page.getByRole('dialog').locator('#generic-submit-confirm').click();
 await expect(page.getByRole('heading',{name:'Version 1 · draft',exact:true})).toBeVisible();
 await page.getByRole('navigation',{name:'Planning activities',exact:true}).getByRole('button',{name:'Formal handover',exact:true}).click();
 const handover=page.locator('section').filter({has:page.getByRole('heading',{name:'Formal handover',exact:true})}).last();
 await handover.getByPlaceholder('Originating office',{exact:true}).fill('Synthetic origin office');await handover.getByPlaceholder('Receiving office',{exact:true}).fill('Synthetic receiving office');
 await handover.getByRole('combobox').selectOption({label:'Synthetic eligible recipient · social worker'});await handover.getByPlaceholder('Reason for handover',{exact:true}).fill('Synthetic continuity transfer.');
 await handover.getByRole('button',{name:'Create handover draft',exact:true}).click();await page.getByRole('dialog').locator('#generic-submit-confirm').click();
 for(const name of ['Request','Endorse','Accept']){await handover.getByRole('button',{name,exact:true}).click();await page.getByRole('dialog').getByRole('button',{name:'Confirm status change',exact:true}).click();}
 await handover.getByRole('button',{name:'Complete handover',exact:true}).click();await page.getByRole('dialog').getByRole('button',{name:'Confirm status change',exact:true}).click();
 await expect(page.getByRole('heading',{name:'You do not have access to this page',exact:true})).toBeVisible();
}

test.describe('case browser history privacy',()=>{
 test('case browser history reauthorizes after the case is handed over elsewhere',async({page,browser})=>{
  await login(page);await page.goto('/cases?search=SYN-HISTORY-REVOKE-2026-0001');await page.getByRole('link',{name:/SYN-HISTORY-REVOKE-2026-0001/}).first().click();await expect(page).toHaveURL(/\/cases\/\d+$/);const caseUrl=page.url();const casePath=new URL(caseUrl).pathname;
  await page.getByRole('link',{name:'Back to case registry',exact:true}).click();await expect(page).toHaveURL(/\/cases$/);
  const context=await browser.newContext();const reviewer=await context.newPage();
  try{
   await login(reviewer);await completeHandover(reviewer,caseUrl,'Synthetic history handover objective.');
   const reauthorization=page.waitForResponse(response=>response.request().method()==='GET'&&new URL(response.url()).pathname===casePath,{timeout:10_000}).catch(()=>null);
   await page.goBack();await expect(page.getByRole('heading',{name:'SYN-HISTORY-REVOKE-2026-0001',exact:true})).toHaveCount(0);
   expect((await reauthorization)?.status()).toBe(403);
  }finally{await context.close();}
 });
 test('legacy plaintext history entries request fresh authorization instead of restoring props',async({page})=>{
  await login(page);await page.goto('/cases?search=SYN-UI-2026-0001');
  await page.getByRole('link',{name:/SYN-UI-2026-0001/}).first().click();await expect(page).toHaveURL(/\/cases\/\d+$/);
  await page.reload();await expect(page.getByRole('heading',{name:'SYN-UI-2026-0001',exact:true})).toBeVisible();
  const casePath=new URL(page.url()).pathname;
  await page.evaluate(()=>{
   const current=history.state;
   const legacy=JSON.parse(document.querySelector('#app')!.getAttribute('data-page')!);
   legacy.props.caseRecord.case_number='SYN-LEGACY-STALE';legacy.encryptHistory=false;
   history.replaceState({page:legacy},'');history.pushState(current,'');
  });
  const reauthorized=page.waitForResponse(response=>new URL(response.url()).pathname===casePath&&response.request().method()==='GET');
  await page.goBack();expect((await reauthorized).status()).toBe(200);
  await expect(page.getByRole('heading',{name:'SYN-UI-2026-0001',exact:true})).toBeVisible();
  await expect(page.getByRole('heading',{name:'SYN-LEGACY-STALE',exact:true})).toHaveCount(0);
  await expect.poll(()=>page.evaluate(()=>history.state?.page instanceof ArrayBuffer)).toBe(true);
 });
 test('case history encryption uses a fresh nonce for every write',async({page})=>{
  await page.addInitScript(()=>{
   const observed:string[]=[];Object.defineProperty(window,'syntheticHistoryNonces',{value:observed});
   const original=crypto.subtle.encrypt.bind(crypto.subtle);
   crypto.subtle.encrypt=(algorithm,key,data)=>{
    if(typeof algorithm==='object' && algorithm.name==='AES-GCM' && 'iv' in algorithm){
     const iv=(algorithm as AesGcmParams).iv;
     const bytes=ArrayBuffer.isView(iv)?new Uint8Array(iv.buffer,iv.byteOffset,iv.byteLength):new Uint8Array(iv);
     observed.push(Array.from(bytes).join(','));
    }
    return original(algorithm,key,data);
   };
  });
  await login(page);await page.goto('/cases?search=SYN-UI-2026-0001');
  await page.getByRole('link',{name:/SYN-UI-2026-0001/}).first().click();await expect(page).toHaveURL(/\/cases\/\d+$/);
  await expect(page.getByRole('heading',{name:'SYN-UI-2026-0001',exact:true})).toBeVisible();
  const counts=await page.evaluate(()=>{const values=(window as unknown as {syntheticHistoryNonces:string[]}).syntheticHistoryNonces;return {writes:values.length,distinct:new Set(values).size};});
  expect(counts.writes).toBeGreaterThanOrEqual(2);expect(counts.distinct).toBe(counts.writes);
 });
 test('case browser history never persists plaintext identity props',async({page})=>{
  await login(page);await page.goto('/cases?search=SYN-UI-2026-0001');
  await page.getByRole('link',{name:/SYN-UI-2026-0001/}).first().click();await expect(page).toHaveURL(/\/cases\/\d+$/);
  await expect(page.getByRole('heading',{name:'SYN-UI-2026-0001',exact:true})).toBeVisible();
  await expect.poll(()=>page.evaluate(()=>Boolean(history.state?.page))).toBe(true);
  const storage=await page.evaluate(()=>({
   identityInHistory:JSON.stringify(history.state).includes('InterfaceRecord'),
   encrypted:history.state.page instanceof ArrayBuffer,
   identityInSession:JSON.stringify(sessionStorage).includes('InterfaceRecord'),
  }));
  expect(storage.identityInHistory).toBe(false);expect(storage.encrypted).toBe(true);expect(storage.identityInSession).toBe(false);
 });
 test('encrypted history permits authorized back and reload then rotates on logout',async({page})=>{
  await page.setViewportSize({width:1440,height:1080});await login(page);await page.goto('/cases?search=SYN-UI-2026-0001');
  await page.getByRole('link',{name:/SYN-UI-2026-0001/}).first().click();await expect(page).toHaveURL(/\/cases\/\d+$/);
  const caseUrl=page.url();await expect(page.getByRole('heading',{name:'SYN-UI-2026-0001',exact:true})).toBeVisible();
  await page.getByRole('link',{name:'Back to case registry',exact:true}).click();await expect(page).toHaveURL(/\/cases$/);
  await page.goBack();await expect(page.getByRole('heading',{name:'SYN-UI-2026-0001',exact:true})).toBeVisible();
  await page.reload();await expect(page.getByRole('heading',{name:'SYN-UI-2026-0001',exact:true})).toBeVisible();
  const originalKey=await page.evaluate(()=>sessionStorage.getItem('historyKey'));expect(originalKey).toBeTruthy();
  await page.getByRole('button',{name:'Log out',exact:true}).click();
  await page.getByRole('dialog',{name:'Sign out of Kalinga',exact:true}).getByRole('button',{name:'Sign out',exact:true}).click();
  await expect(page).toHaveURL(/\/login$/);
  // Logout ends with a full reload of /login (memory is wiped), so read storage once it has settled.
  await expect.poll(async()=>{try{return await page.evaluate(()=>sessionStorage.getItem('historyKey'));}catch{return originalKey;}}).not.toBe(originalKey);
  const denied=await page.request.get(caseUrl);expect(new URL(denied.url()).pathname).toBe('/login');
  await page.goBack();await expect(page.getByRole('heading',{name:'SYN-UI-2026-0001',exact:true})).toHaveCount(0);
 });
});



for(const width of [360,390,768,1024,1440,1920]) test(`KaVAT and Kalinga forms responsive review at ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:1080});await login(page);await page.goto('/cases?search=SYN-UI-2026-0001');
 await page.getByRole('link',{name:/SYN-UI-2026-0001/}).first().click();await expect(page).toHaveURL(/\/cases\/\d+$/);
 await page.getByRole('tab',{name:/^KaVAT/}).click();const panel=page.locator('#case-panel-kavat');
 await expect(panel.getByRole('heading',{name:'Kalinga Vulnerability Assessment Tool (KaVAT)',exact:true})).toBeVisible();
 await expectNoHorizontalOverflow(page);
 let scan=await new AxeBuilder({page}).include('main').analyze();
 expect(scan.violations.filter(item=>['critical','serious'].includes(item.impact??''))).toEqual([]);
 await page.getByRole('tab',{name:/^Kalinga forms/}).click();const forms=page.locator('#case-panel-forms');
 await expect(forms.getByRole('list',{name:'Order of the Kalinga forms'}).getByRole('listitem')).toHaveCount(5);
 await expectNoHorizontalOverflow(page);
 scan=await new AxeBuilder({page}).include('main').analyze();
 expect(scan.violations.filter(item=>['critical','serious'].includes(item.impact??''))).toEqual([]);
});

test.describe('Philippine case opening date',()=>{
 test.use({timezoneId:'America/New_York'});
 test('case opening date stays Philippine on a different device timezone',async({page})=>{
  await login(page);await page.goto('/cases?search=SYN-UI-2026-0006');
  await page.getByRole('link',{name:/SYN-UI-2026-0006/}).first().click();await expect(page).toHaveURL(/\/cases\/\d+$/);
  const opened=page.locator('#case-panel-overview').getByText('Opened',{exact:true}).locator('..').locator('dd');
  await expect(opened).toHaveText('Oct 1, 2026');
 });
});

test('next recorded task targets actual case tasks rather than progress notes',async({page})=>{
 await page.setViewportSize({width:1440,height:1080});await login(page);await page.goto('/cases?search=DEMO-KLG');
 await page.getByRole('link',{name:/DEMO-KLG-/}).first().click();await expect(page).toHaveURL(/\/cases\/\d+$/);
 await page.getByRole('button',{name:'View case tasks',exact:true}).click();
 const target=page.locator('#case-open-tasks');await expect(target).toBeFocused();
 await expect(target.getByRole('heading',{name:'Tasks',exact:true})).toBeVisible();
});

for(const width of [360,390,768,1024,1440,1920]) test(`case workspace overview and recorded workflow at ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:1080}); await login(page); await page.goto('/cases?search=SYN-UI-2026-0001');
 await page.getByRole('link',{name:/SYN-UI-2026-0001/}).first().click();
 await expect(page).toHaveURL(/\/cases\/\d+$/); await page.reload();
 const caseUrl=page.url();
 const payload=JSON.parse((await page.locator('#app').getAttribute('data-page'))!).props;
 await expect(page.getByRole('heading',{name:payload.caseRecord.case_number,exact:true})).toBeVisible();
 const tabs=page.getByRole('tablist',{name:'Case record sections',exact:true});
 await expect(tabs.getByRole('tab')).toHaveCount(7);
 const viewportOverflow=await page.evaluate(()=>document.documentElement.scrollWidth-document.documentElement.clientWidth);
 if(viewportOverflow>1) await test.info().attach('overflow-layout-metadata',{body:JSON.stringify(await page.evaluate(()=>Array.from(document.querySelectorAll('main *')).filter(element=>element.getBoundingClientRect().right>document.documentElement.clientWidth+1).map(element=>({tag:element.tagName,classes:element.getAttribute('class'),right:Math.round(element.getBoundingClientRect().right)})))),contentType:'application/json'});
 await expectNoHorizontalOverflow(page);
 const scan=await new AxeBuilder({page}).include('main').analyze();
 expect(scan.violations.filter(item=>['critical','serious'].includes(item.impact??''))).toEqual([]);
 await page.screenshot({path:`docs/implementation/evidence/ui-05/workspace-${width}.png`,fullPage:true,animations:'disabled'});
 await page.screenshot({path:`docs/implementation/evidence/ui-05/viewport-${width}.png`,animations:'disabled'});
 const nextTask=page.getByRole('button',{name:'View case tasks',exact:true});
 if(await nextTask.count()){
  await nextTask.click();const target=page.locator('#case-open-tasks');await expect(target).toBeFocused();
  await expect(target.getByRole('heading',{name:'Tasks',exact:true})).toBeVisible();
  await expect(target.locator('#case-task-records > div').first()).toContainText('Synthetic urgent safety review');
  await expect(target.locator('#case-task-records > div')).toHaveCount(5);
  await target.getByRole('button',{name:`Show all ${payload.tasks.length} case tasks`,exact:true}).click();
  await expect(target.locator('#case-task-records > div')).toHaveCount(payload.tasks.length);
  await target.getByRole('button',{name:'Show fewer case tasks',exact:true}).click();
  await expect(target.locator('#case-task-records > div')).toHaveCount(5);
 }
 if(payload.transitions.length){await page.getByText('Case status actions',{exact:true}).click();await expect(page.getByRole('button',{name:/^Move to /})).toHaveCount(payload.transitions.length);}
 await tabs.getByRole('tab',{name:'Overview',exact:true}).click(); await page.keyboard.press('ArrowRight');
 await expect(tabs.getByRole('tab',{name:'Client & Intake',exact:true})).toBeFocused();
 await page.keyboard.press('End');await expect(tabs.getByRole('tab',{name:'Documents',exact:true})).toHaveAttribute('aria-selected','true');
 await page.keyboard.press('Home');await expect(tabs.getByRole('tab',{name:'Overview',exact:true})).toHaveAttribute('aria-selected','true');
 await page.getByRole('button',{name:'Reports',exact:true}).click();await page.getByRole('list',{name:'Print a case report'}).getByRole('link',{name:'Case Summary Report',exact:true}).click();
 await expect(page.getByRole('heading',{name:'CASE SUMMARY REPORT',exact:true})).toBeVisible();
 await page.goto(caseUrl); await expect(page.getByRole('heading',{name:payload.caseRecord.case_number,exact:true})).toBeVisible();
 await page.goto(page.url().replace(/\/cases\/(\d+).*/,'/cases/$1/plan-workspace'));
 await expect(page.getByRole('heading',{name:'Case plan',exact:true})).toBeVisible();
});

for(const width of [360,390,768,1024,1440,1920]) test(`intake steps retain entered work and fit ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:1080}); await login(page); await page.goto('/cases/create');
 await expect(page.locator('main').getByRole('link',{name:'field mode',exact:true})).toHaveAttribute('href',/\/offline-workspace$/);
 await page.evaluate(()=>document.fonts.ready);
 await page.screenshot({path:`docs/implementation/evidence/ui-04/start-viewport-${width}.png`,animations:'disabled'});
 await page.getByRole('button',{name:'Start new intake',exact:true}).click();
 const steps=page.getByRole('navigation',{name:'CEFMU intake steps',exact:true}).getByRole('button');
 expect(Math.abs((await steps.first().boundingBox())!.y-(await steps.last().boundingBox())!.y)).toBeLessThanOrEqual(1);
 const capture=async(step:string)=>{
  await expectNoHorizontalOverflow(page);
  const scan=await new AxeBuilder({page}).include('main').analyze();
  expect(scan.violations.filter(item=>['critical','serious'].includes(item.impact??''))).toEqual([]);
  await page.screenshot({path:`docs/implementation/evidence/ui-04/${step}-${width}.png`,fullPage:true,animations:'disabled'});
  await page.screenshot({path:`docs/implementation/evidence/ui-04/${step}-viewport-${width}.png`,animations:'disabled'});
 };
 await capture('privacy');
 await page.getByRole('checkbox',{name:/I gave the privacy notice/}).check();
 await page.getByRole('combobox',{name:/^How was the privacy notice given/}).selectOption('explained_verbally');
 const next=()=>page.getByRole('button',{name:'Continue',exact:true}).click();
 await next();
 await page.getByRole('textbox',{name:'First name *',exact:true}).fill('SyntheticInterface');
 await page.getByRole('textbox',{name:'Last name *',exact:true}).fill('IntakeReview');
 await page.getByLabel('Date of birth *').fill('2014-02-16');
 await page.getByRole('combobox',{name:'Sex *',exact:true}).selectOption('Female');
 await page.getByRole('button',{name:'Add family member',exact:true}).click();
 await page.getByRole('textbox',{name:'Name',exact:true}).last().fill('Synthetic Relative');
 await page.locator('#family-0-relationship_type').selectOption('Aunt');
 await page.getByRole('checkbox',{name:/^Same address as the child/}).last().uncheck();
 await capture('child-contact');
 await next(); await page.getByRole('checkbox',{name:/^Early marriage/}).check(); await page.getByRole('radio',{name:/^Yes, now/}).check(); await capture('case-details'); await next();
 await page.getByRole('checkbox',{name:/^Planned or impending union/}).check();
 await capture('safety');
 await page.getByRole('button',{name:'Back',exact:true}).click();
 await page.getByRole('button',{name:'Back',exact:true}).click();
 await expect(page.getByRole('textbox',{name:'First name *',exact:true})).toHaveValue('SyntheticInterface');
 await next(); await next();
 await expect(page.getByRole('checkbox',{name:/^Planned or impending union/})).toBeChecked();
});

for(const width of [360,390,768,1024,1440,1920]) test(`case registry shared filters and pages at ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:1080}); await login(page); await page.goto('/cases');
 await expect(page.getByRole('heading',{name:'My cases',exact:true})).toBeVisible();
 const intakeAction=page.locator('main').getByRole('link',{name:'New case intake',exact:true});
 expect((await intakeAction.boundingBox())!.height).toBeGreaterThanOrEqual(44);
 if(width<768) expect((await page.getByRole('list',{name:'Authorized case folders',exact:true}).getByRole('link').first().boundingBox())!.y).toBeLessThan(1000);
 await expect(page.getByRole('navigation',{name:'Pagination',exact:true})).toBeVisible();
 await expectNoHorizontalOverflow(page);
 const accessibility=await new AxeBuilder({page}).analyze();
 expect(accessibility.violations.filter(item=>['critical','serious'].includes(item.impact??''))).toEqual([]);
 await page.evaluate(()=>document.fonts.ready);
 await page.screenshot({path:`docs/implementation/evidence/ui-03/cases-${width}.png`,fullPage:true,animations:'disabled'});
 await page.screenshot({path:`docs/implementation/evidence/ui-03/viewport-${width}.png`,animations:'disabled'});
 const pagination=page.getByRole('navigation',{name:'Pagination',exact:true});
 await pagination.getByRole('link',{name:'Next',exact:true}).click();
 await expect(page).toHaveURL(/page=2/);
 await page.getByRole('navigation',{name:'Pagination',exact:true}).getByRole('link',{name:'Previous',exact:true}).click();
 await expect(page).toHaveURL(/page=1/);
 await page.getByRole('searchbox',{name:'Search cases',exact:true}).fill('SYNTHETIC-NO-MATCH-UI03');
 await page.getByRole('button',{name:'Search',exact:true}).click();
 await expect(page.getByText('No cases found',{exact:true})).toBeVisible();
 await expect(page.getByRole('navigation',{name:'Pagination',exact:true})).toContainText('Showing 0-0 of 0');
 await page.getByRole('link',{name:'Clear filters',exact:true}).click();
 await expect(page.getByRole('searchbox',{name:'Search cases',exact:true})).toHaveValue('');
 await page.getByRole('link',{name:/(DEMO-KLG|SYN)-/}).first().click();
 await expect(page).toHaveURL(/\/cases\/\d+$/);
});

for(const width of [360,390,768,1024,1440,1920]) test(`to-do dashboard screenshot and workflows at ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:1080}); await login(page); await page.goto('/dashboard');
 await expect(page.getByRole('heading',{name:/your to-do list/i})).toBeVisible();
 // To-do only: no charts on the dashboard (they are in Analytics).
 await expect(page.getByRole('region',{name:'Supporting caseload analytics'})).toHaveCount(0);
 await expect(page.getByRole('link',{name:'New case intake',exact:true})).toHaveAttribute('href',/\/cases\/create$/);
 await expect(page.getByRole('link',{name:/Analytics and map/})).toHaveAttribute('href',/\/analytics$/);
 await expectNoHorizontalOverflow(page);
 const result=await new AxeBuilder({page}).analyze();
 expect(result.violations.filter(item=>['critical','serious'].includes(item.impact??''))).toEqual([]);
 await page.evaluate(()=>document.fonts.ready);
 await page.screenshot({path:`docs/implementation/evidence/ui-02/dashboard-${width}.png`,fullPage:true,animations:'disabled'});
 await page.screenshot({path:`docs/implementation/evidence/ui-02/viewport-${width}.png`,animations:'disabled'});
 if(width<1024){await page.getByRole('button',{name:'Open navigation',exact:true}).click();await expect(page.locator('#workspace-navigation').getByRole('button',{name:'Close navigation',exact:true})).toBeFocused();await page.keyboard.press('Escape');}
});

test('dashboard empty state is honest and shell collapse stays keyboard usable',async({page})=>{
 await page.setViewportSize({width:1440,height:1080});
 await login(page,{email:'empty-worker@kalinga.test',password:'password'}); await page.goto('/dashboard');
 await expect(page.getByText('No open case tasks recorded',{exact:true})).toBeVisible();
 await expect(page.getByText('Nothing scheduled.',{exact:true})).toBeVisible();
 await page.getByRole('button',{name:'Collapse navigation',exact:true}).click();
 const sidebar=page.locator('#workspace-navigation');
 await expect.poll(async()=>(await sidebar.boundingBox())!.width).toBe(96);
 const logout=(await sidebar.getByRole('button',{name:'Log out',exact:true}).boundingBox())!;
 expect(logout.x+logout.width).toBeLessThanOrEqual(96);
 await expect(sidebar.getByRole('link',{name:'Cases',exact:true})).toBeVisible();
 await page.getByRole('button',{name:'Open notifications',exact:true}).click();
 await expect(page.getByRole('region',{name:'Notifications',exact:true})).toBeVisible();
 await page.getByRole('region',{name:'Notifications',exact:true}).getByRole('button',{name:'Close',exact:true}).click();
 await page.screenshot({path:'docs/implementation/evidence/ui-02/empty-collapsed-1440.png',fullPage:true,animations:'disabled'});
});

test('case planning selects the newly acknowledged draft instead of the prior version',async({page})=>{
 await login(page);await page.goto('/cases?search=SYN-UI-2026-0003');await page.getByRole('link',{name:/SYN-UI-2026-0003/}).first().click();await expect(page).toHaveURL(/\/cases\/\d+$/);
await page.goto(page.url().replace(/\/cases\/(\d+).*/,'/cases/$1/plan-workspace'));
 await page.getByRole('textbox',{name:'Overall objective',exact:true}).fill('Synthetic first plan cycle objective.');
 await page.getByRole('button',{name:'Create draft',exact:true}).click();await page.getByRole('dialog').locator('#generic-submit-confirm').click();
 await expect(page.getByRole('heading',{name:'Version 1 · draft',exact:true})).toBeVisible();
 await page.getByRole('button',{name:'Start a new cycle',exact:true}).click();await page.getByRole('textbox',{name:'Overall objective',exact:true}).fill('Synthetic second plan cycle objective.');
 await page.getByRole('button',{name:'Create draft',exact:true}).click();await page.getByRole('dialog').locator('#generic-submit-confirm').click();
 await expect(page.getByRole('heading',{name:'Version 2 · draft',exact:true})).toBeVisible();
 await expect(page.getByRole('article').filter({has:page.getByRole('heading',{name:'Overall objective',exact:true})})).toContainText('Synthetic second plan cycle objective.');
 await page.getByRole('button',{name:/^Version 1 · draft/}).click();await page.getByRole('button',{name:'Refresh',exact:true}).click();
 await expect(page.getByRole('heading',{name:'Version 1 · draft',exact:true})).toBeVisible();
});
test('formal handover completes for an eligible recipient and removes the former worker view',async({page,browser})=>{
 await login(page);await page.goto('/cases?search=SYN-UI-2026-0002');await page.getByRole('link',{name:/SYN-UI-2026-0002/}).first().click();await expect(page).toHaveURL(/\/cases\/\d+$/);const caseUrl=page.url();
 await completeHandover(page,caseUrl,'Synthetic handover continuity objective.');
 await expect(page.getByRole('heading',{name:'Case plan',exact:true})).toHaveCount(0);
 const context=await browser.newContext();try{const recipient=await context.newPage();await login(recipient,{email:'transfer-worker@kalinga.test',password:'password'});await recipient.goto(caseUrl);await expect(recipient.getByRole('heading',{name:'SYN-UI-2026-0002',exact:true})).toBeVisible();}finally{await context.close();}
});