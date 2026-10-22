import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, expectNoHorizontalOverflow } from './helpers';
test.use({timezoneId:'America/Los_Angeles'});

async function openConferences(page: Page, number='SYN-CONTEXT-2026-0001') {
    await login(page); await page.goto('/cases?search='+encodeURIComponent(number));
    await page.getByRole('link', { name: new RegExp(number) }).first().click();
    await page.getByRole('tab', { name: 'Progress notes', exact: true }).click();
    await page.getByRole('link', { name: 'Open Case Conferences', exact: true }).click();
    await expect(page.getByRole('button',{name:'New conference',exact:true})).toBeEnabled();
}

async function confirmForm(page:Page){await page.getByRole('dialog').locator('#generic-submit-confirm').click();}
async function createDraft(page:Page,purpose:string){
    if(!await page.locator('#conference-create-purpose').isVisible())await page.getByRole('button',{name:'New conference',exact:true}).click();
    await page.getByRole('textbox',{name:'Purpose',exact:true}).fill(purpose);
    const response=page.waitForResponse(response=>response.request().method()==='POST'&&/\/conferences$/.test(new URL(response.url()).pathname));
    await page.getByRole('button',{name:'Create draft',exact:true}).click();await confirmForm(page);
    const acknowledged=await response;expect(acknowledged.status()).toBe(201);return (await acknowledged.json()).id as number;
}
async function activity(page:Page,name:string,key:string){const control=page.getByRole('combobox',{name:'Conference activity',exact:true});if(await control.isVisible())await control.selectOption(key);else await page.getByRole('navigation',{name:'Conference activities',exact:true}).getByRole('button',{name,exact:true}).click();}

test('conference draft creation selects the acknowledged real draft without an Inertia JSON error', async ({ page }) => {
    await openConferences(page);
    const id=await createDraft(page,'Synthetic acknowledged conference purpose.');
    await expect(page.getByRole('heading', { name: /^Conference #\d+ - draft$/ })).toBeVisible();
    await expect(page.locator('#conference-workspace')).toContainText('Synthetic acknowledged conference purpose.');
    await expect(page.locator('#workspace-navigation').getByRole('link',{name:'Cases',exact:true})).toHaveAttribute('aria-current','page');
    expect(id).toBeGreaterThan(0);
});

test('Other conference errors focus the summary and retain rejected draft fields',async({page})=>{
 await openConferences(page);await page.getByRole('button',{name:'New conference',exact:true}).click();
 const form=page.getByRole('form',{name:'Schedule conference',exact:true});await form.getByRole('combobox',{name:'Conference type',exact:true}).selectOption('other');await form.getByRole('textbox',{name:'Purpose',exact:true}).fill('Synthetic recoverable Other purpose.');await form.getByRole('button',{name:'Create draft',exact:true}).click();await confirmForm(page);await expect(page.getByRole('alert').first()).toContainText('Explain an Other');await expect(page.getByRole('alert').first()).toBeFocused();await expect(form.getByRole('textbox',{name:'Purpose',exact:true})).toHaveValue('Synthetic recoverable Other purpose.');
 await form.getByRole('textbox',{name:'Reason for Other type',exact:true}).fill('Synthetic documented reason.');await form.getByRole('button',{name:'Create draft',exact:true}).click();await confirmForm(page);await expect(page.getByRole('heading',{name:/^Conference #\d+ - draft$/})).toBeVisible();
});

test('conference supports real attendance decisions linked actions rescheduling independent review and addendum',async({page,browser})=>{
 await openConferences(page,'SYN-CONFERENCE-REVIEW');const url=page.url();const id=await createDraft(page,'Synthetic agreed conference purpose.');await expect(page.getByRole('heading',{name:`Conference #${id} - draft`,exact:true})).toBeVisible();
 const record=page.getByRole('form',{name:'Professional conference record',exact:true});await record.getByRole('textbox',{name:'Case updates',exact:true}).fill('Synthetic reviewed conference update.');await record.getByRole('textbox',{name:'Key issues',exact:true}).fill('Synthetic recorded education issue.');await record.locator('#conference-record-next_review_at').fill('2026-11-02T09:15');await record.getByRole('button',{name:'Save conference record',exact:true}).click();await confirmForm(page);await expect(page.getByRole('status').filter({hasText:'Conference record saved.'})).toBeVisible();
 await activity(page,'Attendance','attendance');const participant=page.getByRole('form',{name:'Add participant',exact:true});await participant.getByRole('textbox',{name:'Participant name',exact:true}).fill('Synthetic professional participant');await participant.getByRole('combobox',{name:'Attendance status',exact:true}).selectOption('attended');await participant.getByRole('button',{name:'Record participant',exact:true}).click();await confirmForm(page);await expect(page.locator('#conference-workspace article').filter({has:page.getByRole('heading',{name:'Synthetic professional participant',exact:true})})).toContainText('attended');
 await activity(page,'Decisions & actions','decisions');const decision=page.getByRole('form',{name:'Decision or recommendation',exact:true});await decision.getByRole('combobox',{name:'Record kind',exact:true}).selectOption('recommendation');await decision.getByRole('textbox',{name:'Decision or recommendation',exact:true}).fill('Synthetic recommendation retained separately.');await decision.getByRole('button',{name:'Record decision',exact:true}).click();await confirmForm(page);await expect(page.getByText('Synthetic recommendation retained separately.',{exact:true})).toBeVisible();
 const action=page.getByRole('form',{name:'Agreed follow-up action',exact:true});await action.getByRole('textbox',{name:'Agreed action',exact:true}).fill('Synthetic agreed linked follow-up action.');const source=action.getByRole('combobox',{name:'Existing operational source',exact:true});const option=await source.locator('option').filter({hasText:/^task #/}).first().getAttribute('value');expect(option).toBeTruthy();await source.selectOption(option!);await action.getByRole('button',{name:'Record action',exact:true}).click();await confirmForm(page);await expect(page.getByText(/^Linked task #\d+ - open$/)).toBeVisible();
 await activity(page,'Review & history','review');const reschedule=page.getByRole('form',{name:'Reschedule conference',exact:true});await reschedule.locator('#conference-reschedule-scheduled_at').fill('2026-10-22T14:30');await reschedule.getByRole('textbox',{name:'Rescheduling reason',exact:true}).fill('Synthetic availability reason.');await reschedule.getByRole('button',{name:'Save new schedule',exact:true}).click();await confirmForm(page);await expect(page.getByRole('heading',{name:`Conference #${id} - scheduled`,exact:true})).toBeVisible();await expect(page.locator('#conference-workspace')).toContainText('2:30 PM');
 page.once('dialog',dialog=>dialog.accept());await page.getByRole('button',{name:'Submit for review',exact:true}).click();await expect(page.getByRole('heading',{name:`Conference #${id} - for review`,exact:true})).toBeVisible();await expect(page.getByRole('button',{name:'Finalize conference',exact:true})).toHaveCount(0);
 const context=await browser.newContext({timezoneId:'Asia/Tokyo'});const reviewer=await context.newPage();try{
  await login(reviewer,{email:'assessment-reviewer@kalinga.test',password:'password'});await reviewer.goto(url);await expect(reviewer.getByRole('heading',{name:`Conference #${id} - for review`,exact:true})).toBeVisible();await activity(reviewer,'Review & history','review');
  reviewer.once('dialog',dialog=>dialog.accept());await reviewer.getByRole('button',{name:'Return for revision',exact:true}).click();await expect(reviewer.getByRole('alert').first()).toContainText('Independent reviewer comments');await expect(reviewer.getByRole('alert').first()).toBeFocused();
  await reviewer.getByRole('textbox',{name:'Reviewer comments',exact:true}).fill('Synthetic clarify the follow-up discussion.');reviewer.once('dialog',dialog=>dialog.accept());await reviewer.getByRole('button',{name:'Return for revision',exact:true}).click();await expect(reviewer.getByRole('heading',{name:`Conference #${id} - draft`,exact:true})).toBeVisible();
  await page.getByRole('button',{name:'Refresh',exact:true}).click();await expect(page.getByRole('heading',{name:`Conference #${id} - draft`,exact:true})).toBeVisible();await expect(page.locator('#conference-workspace')).toContainText('Synthetic clarify the follow-up discussion.');page.once('dialog',dialog=>dialog.accept());await page.getByRole('button',{name:'Submit for review',exact:true}).click();await expect(page.getByRole('heading',{name:`Conference #${id} - for review`,exact:true})).toBeVisible();
  await reviewer.getByRole('button',{name:'Refresh',exact:true}).click();await expect(reviewer.getByRole('button',{name:'Finalize conference',exact:true})).toBeVisible();reviewer.once('dialog',dialog=>dialog.accept());await reviewer.getByRole('button',{name:'Finalize conference',exact:true}).click();await expect(reviewer.getByRole('heading',{name:`Conference #${id} - final`,exact:true})).toBeVisible();
  await page.getByRole('button',{name:'Refresh',exact:true}).click();await expect(page.getByRole('heading',{name:`Conference #${id} - final`,exact:true})).toBeVisible();const addendum=page.getByRole('form',{name:'Create linked addendum',exact:true});await addendum.getByRole('textbox',{name:'Addendum reason',exact:true}).fill('Synthetic supplementary clarification.');await addendum.getByRole('textbox',{name:'Addendum purpose',exact:true}).fill('Synthetic linked addendum purpose.');await addendum.getByRole('button',{name:'Create addendum',exact:true}).click();await confirmForm(page);await expect(page.getByRole('heading',{name:`Conference #${id} - final`,exact:true})).toHaveCount(0);await expect(page.locator('#conference-workspace')).toContainText(`Linked addendum to conference #${id}`);
  await page.reload();await page.getByRole('navigation',{name:'Conference records',exact:true}).getByRole('button',{name:new RegExp('^#'+id+' -')}).click();await expect(page.getByRole('heading',{name:`Conference #${id} - final`,exact:true})).toBeVisible();await expect(page.locator('#conference-workspace')).toContainText('Synthetic reviewed conference update.');expect(await page.evaluate(()=>JSON.stringify(localStorage)+JSON.stringify(sessionStorage))).not.toContain('Synthetic reviewed conference update.');
 }finally{await context.close();}
});

for(const status of [422,503])test(`acknowledged conference creation followed by ${status} refresh failure cannot repeat the write`,async({page})=>{
 await openConferences(page);let posted=0,fail=true;
 await page.route('**/cases/*/conferences',async route=>{const method=route.request().method();if(method==='POST'){posted++;await route.continue();}else if(posted&&fail){fail=false;await route.fulfill({status,contentType:'application/json',body:JSON.stringify({message:'Synthetic refresh unavailable.'})});}else await route.continue();});
 await createDraft(page,`Synthetic saved conference before refresh ${status}.`);await expect(page.getByRole('alert').first()).toContainText('The change was saved');await expect(page.getByRole('button',{name:'Create draft',exact:true})).toBeDisabled();expect(posted).toBe(1);
 await page.getByRole('button',{name:'Refresh',exact:true}).click();await expect(page.locator('#conference-workspace')).toContainText(`Synthetic saved conference before refresh ${status}.`);await expect(page.getByRole('button',{name:'New conference',exact:true})).toBeEnabled();expect(posted).toBe(1);
});

test('interrupted acknowledgement preserves new conference input until records are reviewed without automatically duplicating it',async({page})=>{
 await openConferences(page);let posted=0;
 await page.route('**/cases/*/conferences',async route=>{if(route.request().method()==='POST'){posted++;await route.fetch();await route.abort('failed');}else await route.continue();});
 if(!await page.locator('#conference-create-purpose').isVisible())await page.getByRole('button',{name:'New conference',exact:true}).click();await page.getByRole('textbox',{name:'Purpose',exact:true}).fill('Synthetic interrupted acknowledgement purpose.');await page.getByRole('button',{name:'Create draft',exact:true}).click();await confirmForm(page);await expect(page.getByRole('alert').first()).toContainText('outcome could not be confirmed');await expect(page.getByRole('textbox',{name:'Purpose',exact:true})).toHaveValue('Synthetic interrupted acknowledgement purpose.');await expect(page.getByRole('button',{name:'Create draft',exact:true})).toBeDisabled();
 await page.getByRole('button',{name:'Refresh',exact:true}).click();await expect(page.getByRole('button',{name:'New conference',exact:true})).toBeEnabled();await page.getByRole('navigation',{name:'Conference records',exact:true}).getByRole('button').first().click();await expect(page.locator('#conference-workspace')).toContainText('Synthetic interrupted acknowledgement purpose.');expect(posted).toBe(1);page.once('dialog',dialog=>dialog.accept());await page.getByRole('button',{name:'Discard unfinished new conference',exact:true}).click();await expect(page.getByRole('button',{name:'Discard unfinished new conference',exact:true})).toHaveCount(0);expect(posted).toBe(1);
});

test('unfinished attendance remains isolated per conference and blocks premature workflow changes',async({page})=>{
 await openConferences(page);const first=await createDraft(page,'Synthetic first isolated conference.');await expect(page.getByRole('heading',{name:`Conference #${first} - draft`,exact:true})).toBeVisible();await activity(page,'Attendance','attendance');await page.getByRole('textbox',{name:'Participant name',exact:true}).fill('Synthetic unfinished attendee');
 const second=await createDraft(page,'Synthetic second isolated conference.');await expect(page.getByRole('heading',{name:`Conference #${second} - draft`,exact:true})).toBeVisible();await activity(page,'Attendance','attendance');await expect(page.getByRole('textbox',{name:'Participant name',exact:true})).toHaveValue('');
 await page.getByRole('navigation',{name:'Conference records',exact:true}).getByRole('button',{name:new RegExp('^#'+first+' -')}).click();await expect(page.getByRole('textbox',{name:'Participant name',exact:true})).toHaveValue('Synthetic unfinished attendee');await activity(page,'Review & history','review');await page.getByRole('button',{name:'Submit for review',exact:true}).click();await expect(page.getByRole('alert').first()).toContainText('Save the unfinished conference entries');await expect(page.getByRole('heading',{name:`Conference #${first} - draft`,exact:true})).toBeVisible();
 let prompted=false;page.once('dialog',async dialog=>{prompted=true;await dialog.dismiss();});await page.getByRole('link',{name:'Back to case',exact:true}).click();await expect.poll(()=>prompted).toBe(true);await activity(page,'Attendance','attendance');await expect(page.getByRole('textbox',{name:'Participant name',exact:true})).toHaveValue('Synthetic unfinished attendee');
});

test('rescheduling cannot strand unfinished discussion in a read-only conference state',async({page})=>{
 await openConferences(page);const id=await createDraft(page,'Synthetic protected rescheduling context.');await expect(page.getByRole('heading',{name:`Conference #${id} - draft`,exact:true})).toBeVisible();await page.getByRole('textbox',{name:'Case updates',exact:true}).fill('Synthetic unfinished discussion before rescheduling.');await activity(page,'Review & history','review');const form=page.getByRole('form',{name:'Reschedule conference',exact:true});await form.locator('#conference-reschedule-scheduled_at').fill('2026-10-24T10:00');await form.getByRole('textbox',{name:'Rescheduling reason',exact:true}).fill('Synthetic proposed new schedule.');await form.getByRole('button',{name:'Save new schedule',exact:true}).click();await confirmForm(page);await expect(page.getByRole('alert').first()).toContainText('Save the unfinished conference entries');await expect(page.getByRole('heading',{name:`Conference #${id} - draft`,exact:true})).toBeVisible();await activity(page,'Conference record','record');await expect(page.getByRole('textbox',{name:'Case updates',exact:true})).toHaveValue('Synthetic unfinished discussion before rescheduling.');
});

for(const width of [360,390,768,1024,1440,1920])test(`conference responsive activities at ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:1080});await openConferences(page,'SYN-CONFERENCE-REVIEW');await expect(page.locator('#conference-workspace')).toBeVisible();
 for(const [view,name] of [['record','Conference record'],['attendance','Attendance'],['decisions','Decisions & actions'],['review','Review & history']]){
  await activity(page,name,view);await page.locator('#conference-workspace').evaluate(element=>element.scrollIntoView({block:'start'}));await expectNoHorizontalOverflow(page);const scan=await new AxeBuilder({page}).include('main').analyze();expect(scan.violations.filter(item=>['serious','critical'].includes(item.impact??''))).toEqual([]);await page.screenshot({path:`docs/implementation/evidence/ui-12/${view}-${width}.png`,animations:'disabled'});
 }
});

test('confirmed conference case denial clears the stale record and unfinished sensitive editor',async({page})=>{
 await openConferences(page,'SYN-CONFERENCE-DENIAL');const id=await createDraft(page,'Synthetic denied conference narrative.');await expect(page.getByRole('heading',{name:`Conference #${id} - draft`,exact:true})).toBeVisible();await page.getByRole('textbox',{name:'Case updates',exact:true}).fill('Synthetic denied unfinished discussion.');const caseId=Number(new URL(page.url()).pathname.match(/\/cases\/(\d+)/)![1]);
 await page.evaluate(async caseId=>{await window.axios.post('/cases/'+caseId,{_method:'delete'});return true;},caseId);await page.getByRole('button',{name:'Refresh',exact:true}).click();await expect(page.locator('#conference-workspace')).toHaveCount(0);await expect(page.locator('body')).not.toContainText('Synthetic denied conference narrative.');await expect(page.locator('body')).not.toContainText('Synthetic denied unfinished discussion.');expect(await page.evaluate(()=>JSON.stringify(localStorage)+JSON.stringify(sessionStorage))).not.toContain('Synthetic denied');
});
