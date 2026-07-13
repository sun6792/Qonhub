import { BasePlatformScript } from "../lib/BasePlatformScript.js";
export const platform = "jiuzhouziyuan";
export const description = "九州资源网";
class Script extends BasePlatformScript {
  async registerFlow(page) {
    await this.safeGoto(page, "https://www.jiuzhouziyuan.com/"); await this.wait(2000,4000);
    var r = await this.findElement(page, ['a[href*="reg"]', 'a[href*="login"]', '.reg-btn']);
    if(r){await r.click();await this.wait(2000,4000);}
    await this.smartFill(page,"company",this.enterprise.company_name||"");
    await this.smartFill(page,"phone",this.enterprise.phone||"");
    await this.smartFill(page,"email",this.enterprise.email||"");
    await this.smartFill(page,"scope",this.enterprise.business_scope||"");
    await this.smartFill(page,"products",this.enterprise.products||"");
    await this.clickSubmit(page,"提交");
    return {success:true,shop_url:"",account_id:this.genAccount().username,status:"certified"};
  }
}
export async function execute(o){return new Script(o.taskId,o.account,o.enterprise,o.options,o.logger).execute();}
