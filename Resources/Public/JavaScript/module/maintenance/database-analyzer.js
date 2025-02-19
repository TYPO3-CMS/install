/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */
import{AbstractInteractableModule as f}from"@typo3/install/module/abstract-interactable-module.js";import z from"@typo3/backend/modal.js";import p from"@typo3/backend/notification.js";import k from"@typo3/core/ajax/ajax-request.js";import{InfoBox as A}from"@typo3/install/renderable/info-box.js";import S from"@typo3/install/renderable/severity.js";import d from"@typo3/install/router.js";import h from"@typo3/core/event/regular-event.js";import x from"@typo3/core/security-utility.js";var e;(function(t){t.analyzeTrigger=".t3js-databaseAnalyzer-analyze",t.executeTrigger=".t3js-databaseAnalyzer-execute",t.outputContainer=".t3js-databaseAnalyzer-output",t.notificationContainer=".t3js-databaseAnalyzer-notification",t.suggestionBlock="#t3js-databaseAnalyzer-suggestion-block",t.suggestionBlockCheckbox=".t3js-databaseAnalyzer-suggestion-block-checkbox",t.suggestionBlockLegend=".t3js-databaseAnalyzer-suggestion-block-legend",t.suggestionBlockLabel=".t3js-databaseAnalyzer-suggestion-block-label",t.suggestionList=".t3js-databaseAnalyzer-suggestion-list",t.suggestionLineTemplate="#t3js-databaseAnalyzer-suggestion-line-template",t.suggestionLineCheckbox=".t3js-databaseAnalyzer-suggestion-line-checkbox",t.suggestionLineLabel=".t3js-databaseAnalyzer-suggestion-line-label",t.suggestionLineStatement=".t3js-databaseAnalyzer-suggestion-line-statement",t.suggestionLineCurrent=".t3js-databaseAnalyzer-suggestion-line-current",t.suggestionLineCurrentValue=".t3js-databaseAnalyzer-suggestion-line-current-value",t.suggestionLineCount=".t3js-databaseAnalyzer-suggestion-line-count",t.suggestionLineCountValue=".t3js-databaseAnalyzer-suggestion-line-count-value"})(e||(e={}));class q extends f{initialize(s){super.initialize(s),this.loadModuleFrameAgnostic("@typo3/install/renderable/info-box.js").then(()=>{this.getData()}),new h("click",(a,r)=>{r.closest("fieldset").querySelectorAll('input[type="checkbox"]').forEach(c=>{c.checked=r.checked})}).delegateTo(s,e.suggestionBlockCheckbox),new h("click",a=>{a.preventDefault(),this.clearNotifications(),this.analyze()}).delegateTo(s,e.analyzeTrigger),new h("click",a=>{a.preventDefault(),this.clearNotifications(),this.execute()}).delegateTo(s,e.executeTrigger)}getData(){const s=this.getModalBody();new k(d.getUrl("databaseAnalyzer")).get({cache:"no-cache"}).then(async a=>{const r=await a.resolve();r.success===!0?(s.innerHTML=r.html,z.setButtons(r.buttons),this.analyze()):p.error("Something went wrong","The request was not processed successfully. Please check the browser's console and TYPO3's log.")},a=>{d.handleAjaxError(a,s)})}analyze(){this.setModalButtonsState(!1);const s=this.getModalBody(),a=s.querySelector(e.outputContainer),r=this.renderProgressBar(a,{label:"Analyzing current database schema..."});new h("change",()=>{const c=a.querySelectorAll(":checked").length>0;this.setModalButtonState(this.getModalFooter().querySelector(e.executeTrigger),c)}).delegateTo(a,'input[type="checkbox"]'),new k(d.getUrl("databaseAnalyzerAnalyze")).get({cache:"no-cache"}).then(async c=>{const l=await c.resolve();l.success===!0?(Array.isArray(l.status)&&(r.remove(),l.status.forEach(o=>{a.append(A.create(o.severity,o.title,o.message))})),Array.isArray(l.suggestions)&&(l.suggestions.forEach(o=>{const u=s.querySelector(e.suggestionBlock).content.cloneNode(!0),g=o.key;u.querySelector(e.suggestionBlockLegend).innerText=o.label,u.querySelector(e.suggestionBlockCheckbox).setAttribute("id","t3-install-"+g+"-checkbox"),o.enabled&&u.querySelector(e.suggestionBlockCheckbox).setAttribute("checked","checked"),u.querySelector(e.suggestionBlockLabel).setAttribute("for","t3-install-"+g+"-checkbox"),o.children.forEach(n=>{const i=s.querySelector(e.suggestionLineTemplate).content.cloneNode(!0),y=n.hash,b=i.querySelector(e.suggestionLineCheckbox);b.setAttribute("id","t3-install-db-"+y),b.setAttribute("data-hash",y),o.enabled&&b.setAttribute("checked","checked"),i.querySelector(e.suggestionLineLabel).setAttribute("for","t3-install-db-"+y),i.querySelector(e.suggestionLineStatement).innerText=n.statement,typeof n.current<"u"&&(i.querySelector(e.suggestionLineCurrentValue).innerText=n.current,i.querySelector(e.suggestionLineCurrent).style.display="inline"),typeof n.rowCount<"u"&&(i.querySelector(e.suggestionLineCountValue).innerText=n.rowCount,i.querySelector(e.suggestionLineCount).style.display="inline"),u.querySelector(e.suggestionList).append(i)}),a.append(u)}),this.setModalButtonState(this.getModalFooter().querySelector(e.analyzeTrigger),!0),this.setModalButtonState(this.getModalFooter().querySelector(e.executeTrigger),a.querySelectorAll(":checked").length>0)),l.suggestions.length===0&&l.status.length===0&&a.append(A.create(S.ok,"Database schema is up to date. Good job!"))):(p.error("Something went wrong","The request was not processed successfully. Please check the browser's console and TYPO3's log."),this.setModalButtonState(this.getModalFooter().querySelector(e.analyzeTrigger),!0),this.setModalButtonState(this.getModalFooter().querySelector(e.executeTrigger),!1))},c=>{d.handleAjaxError(c,s),this.setModalButtonState(this.getModalFooter().querySelector(e.analyzeTrigger),!0),this.setModalButtonState(this.getModalFooter().querySelector(e.executeTrigger),!1)})}execute(){this.setModalButtonsState(!1);const s=this.getModalBody(),a=this.getModuleContent().dataset.databaseAnalyzerExecuteToken,r=s.querySelector(e.outputContainer),c=s.querySelector(e.notificationContainer),l=[];r.querySelectorAll(".t3js-databaseAnalyzer-suggestion-line input:checked").forEach(o=>{l.push(o.dataset.hash)}),this.renderProgressBar(r,{label:"Executing database updates..."}),new k(d.getUrl()).post({install:{action:"databaseAnalyzerExecute",token:a,hashes:l}}).then(async o=>{const u=await o.resolve();if(Array.isArray(u.status)){let g="";u.status.forEach(n=>{if(n.severity===S.error){const i=new x;g+="<li>"+i.encodeHtml(n.message)+"</li>"}else p.showMessage(n.title,n.message,n.severity)}),g!==""&&(c.innerHTML=`<div class="alert alert-danger">
                <div class="alert-inner">
                  <div class="alert-icon">
                      <span class="icon-emphasized">
                          <typo3-backend-icon identifier="actions-close" size="small"></typo3-backend-icon>
                      </span>
                  </div>
                  <div class="alert-content">
                      <div class="alert-title">Database update failed</div>
                      <div class="alert-message">
                        <ul>${g}</ul>
                      </div>
                  </div>
                </div>
              </div>`)}this.analyze()},o=>{d.handleAjaxError(o,s)}).finally(()=>{this.setModalButtonState(this.getModalFooter().querySelector(e.analyzeTrigger),!0),this.setModalButtonState(this.getModalFooter().querySelector(e.executeTrigger),!1)})}clearNotifications(){this.currentModal.querySelector(e.notificationContainer).replaceChildren("")}}var C=new q;export{C as default};
