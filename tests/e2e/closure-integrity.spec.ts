import { expect, test, type Page } from '@playwright/test';
import { login } from './helpers';

async function write(page:Page,url:string,body:Record<string,string>) {
    return page.evaluate(async ({url,body}) => {
        try { return (await window.axios.post(url,body)).status; }
        catch (cause) { return (cause as {response?:{status?:number}}).response?.status ?? 0; }
    },{url,body});
}

test('real closure endpoints preserve independent review and reviewer-only closing',async({page,browser})=>{
    await login(page);await page.goto('/cases?search=SYN-CLOSURE-INTEGRITY');
    await page.getByRole('link',{name:/SYN-CLOSURE-INTEGRITY/}).first().click();
    await expect(page).toHaveURL(/\/cases\/\d+$/);
    const url=page.url();const id=Number(new URL(url).pathname.match(/\/cases\/(\d+)/)![1]);
    expect(await write(page,`/cases/${id}/closure`,{closure_summary:'Synthetic independently reviewed closure recommendation.'})).toBe(200);
    expect(await write(page,`/cases/${id}/closure-review`,{_method:'patch'})).toBe(422);
    expect(await write(page,`/cases/${id}/status`,{_method:'patch',status:'CLOSED'})).toBe(422);
    const context=await browser.newContext();const reviewer=await context.newPage();
    try {
        await login(reviewer,{email:'assessment-reviewer@kalinga.test',password:'password'});await reviewer.goto(url);
        expect(await write(reviewer,`/cases/${id}/closure-review`,{_method:'patch'})).toBe(200);
        expect(await write(page,`/cases/${id}/status`,{_method:'patch',status:'CLOSED'})).toBe(403);
        expect(await write(reviewer,`/cases/${id}/status`,{_method:'patch',status:'CLOSED'})).toBe(200);
        await page.reload();await expect(page.getByRole('region',{name:'Case folder summary',exact:true})).toContainText('Closed');
        expect(await write(page,`/cases/${id}/status`,{_method:'patch',status:'REOPENED'})).toBe(200);
        expect(await write(page,`/cases/${id}/status`,{_method:'patch',status:'IN_PROGRESS'})).toBe(200);
        expect(await write(page,`/cases/${id}/status`,{_method:'patch',status:'FOR_CLOSURE_REVIEW'})).toBe(200);
        expect(await write(reviewer,`/cases/${id}/status`,{_method:'patch',status:'CLOSED'})).toBe(422);
        await page.reload();await expect(page.getByRole('region',{name:'Case folder summary',exact:true})).toContainText('For Closure Review');
        expect(await page.evaluate(()=>JSON.stringify(localStorage)+JSON.stringify(sessionStorage))).not.toContain('Synthetic independently reviewed closure recommendation.');
    } finally { await context.close(); }
});
