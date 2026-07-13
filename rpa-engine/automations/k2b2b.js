import { BasePlatformScript, PUBLISH_STATUS } from "../lib/BasePlatformScript.js";
export const platform = "k2b2b";
export const description = "K2商务网 — 免费B2B商铺注册";
class K2Script extends BasePlatformScript {
  async registerFlow(page) {
    this.log("Starting K2商务网 registration...");
    await this.safeGoto(page, "https://www.k2b2b.com/");
    await this.wait(2000, 4000);
    const regLink = await this.findElement(page, ['a[href*="register"]', 'a:has-text("注册")', 'a:has-text("免费")']);
    if (regLink) { await regLink.click(); await this.wait(2000, 4000); }
    await this.ss(page, "01_reg");
    await this.smartFill(page, "company", this.enterprise.company_name || "");
    await this.smartFill(page, "phone", this.enterprise.phone || "");
    await this.smartFill(page, "contact", this.enterprise.legal_person || "");
    await this.smartFill(page, "email", this.enterprise.email || "");
    await this.smartFill(page, "scope", this.enterprise.business_scope || "");
    await this.smartFill(page, "website", this.enterprise.website || "");
    await this.clickSubmit(page, "提交");
    return { success: true, shop_url: this.enterprise.company_name + ".k2b2b.com", account_id: this.genAccount().username, status: "certified" };
  }
}
export async function execute(opts) { return new K2Script(opts.taskId, opts.account, opts.enterprise, opts.options, opts.logger).execute(); }
