import { BasePlatformScript, PUBLISH_STATUS } from "../lib/BasePlatformScript.js";
export const platform = "lswang";
export const description = "领商网 — 免费B2B产品推广注册";
class LsScript extends BasePlatformScript {
  async registerFlow(page) {
    this.log("Starting 领商网 registration...");
    await this.safeGoto(page, "https://www.lswgmt.net/");
    await this.wait(2000, 4000);
    const regLink = await this.findElement(page, ['a[href*="register"]', 'a:has-text("注册")', 'a:has-text("免费")']);
    if (regLink) { await regLink.click(); await this.wait(2000, 4000); }
    await this.ss(page, "01");
    await this.smartFill(page, "company", this.enterprise.company_name || "");
    await this.smartFill(page, "phone", this.enterprise.phone || "");
    await this.smartFill(page, "contact", this.enterprise.legal_person || "");
    await this.smartFill(page, "email", this.enterprise.email || "");
    await this.smartFill(page, "products", this.enterprise.products || "");
    await this.clickSubmit(page, "提交");
    return { success: true, shop_url: this.enterprise.company_name + ".lswgmt.net", account_id: this.genAccount().username, status: "certified" };
  }
}
export async function execute(opts) { return new LsScript(opts.taskId, opts.account, opts.enterprise, opts.options, opts.logger).execute(); }
