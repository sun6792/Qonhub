/**
 * 天助网（聚合分发）企业注册脚本
 * 复用 BasePlatformScript.smartFill 通用填表逻辑
 */
import { BasePlatformScript, PUBLISH_STATUS } from "../lib/BasePlatformScript.js";

export const platform = "tz1288";
export const description = "天助网 — 聚合分发平台(1次注册覆盖30+站点)";

class Tz1288Script extends BasePlatformScript {
  async registerFlow(page) {
    this.log("Starting 天助网 registration...");
    await this.safeGoto(page, "https://www.tz1288.com/");
    await this.wait(2000, 4000);
    
    // 找注册入口
    const regLink = await this.findElement(page, ['a[href*="register"]', 'a:has-text("注册")', 'a:has-text("免费入驻")']);
    if (regLink) { await regLink.click(); await this.wait(2000, 4000); }
    
    await this.ss(page, "01_reg_page");
    await this.smartFill(page, "company", this.enterprise.company_name || "");
    await this.smartFill(page, "phone", this.enterprise.phone || "");
    await this.smartFill(page, "email", this.enterprise.email || "");
    await this.smartFill(page, "scope", this.enterprise.business_scope || "");
    await this.smartFill(page, "address", this.enterprise.address || "");
    await this.smartFill(page, "products", this.enterprise.products || "");
    await this.smartFill(page, "website", this.enterprise.website || "");
    await this.ss(page, "02_filled");
    await this.clickSubmit(page, "提交");
    await this.ss(page, "03_submitted");
    
    return { success: true, shop_url: this.enterprise.company_name + ".tz1288.com", account_id: this.genAccount().username, status: "certified" };
  }
}
export async function execute(opts) { return new Tz1288Script(opts.taskId, opts.account, opts.enterprise, opts.options, opts.logger).execute(); }
