/**
 * TDMchecker Extension - JavaScript for injecting TDM status into feed management page
 * This script injects the TDM opt-out status display into the feed configuration page
 */

(function() {
    'use strict';
    
    console.log('TDMchecker: Script loaded');

    function injectTDMStatus() {
        // Check if we're on a feed configuration page
        // Try multiple ways to detect the page
        let isFeedPage = false;
        let tdmData = null;
        
        if (typeof window.context !== 'undefined') {
            if (typeof window.context.get === 'function') {
                const controller = window.context.get('controller') || '';
                const action = window.context.get('action') || '';
                isFeedPage = (controller === 'feed' && (action === 'update' || action === 'configure'));
                tdmData = window.context.get('tdmchecker');
            } else if (window.context.controller === 'feed') {
                isFeedPage = (window.context.action === 'update' || window.context.action === 'configure');
                tdmData = window.context.tdmchecker;
            }
        }
        
        // Also check URL
        if (!isFeedPage && window.location.href.includes('/feed/configure') || window.location.href.includes('/feed/update')) {
            isFeedPage = true;
        }
        
        if (!isFeedPage) {
            return;
        }

        // Get TDM checker data from context or try to extract from page
        if (!tdmData || !tdmData.feedId) {
            // Try to extract feed ID from URL or form
            const urlMatch = window.location.href.match(/[?&]id=(\d+)/);
            const feedId = urlMatch ? parseInt(urlMatch[1]) : null;
            
            if (!feedId) {
                // Try to find feed ID in form
                const feedIdInput = document.querySelector('input[name="id"], input[name="feed_id"]');
                if (feedIdInput) {
                    tdmData = { feedId: parseInt(feedIdInput.value) };
                } else {
                    return;
                }
            } else {
                tdmData = { feedId: feedId };
            }
        }
        
        if (!tdmData || !tdmData.feedId) {
            return;
        }

        // Check if we've already injected the TDM status
        if (document.getElementById('tdmchecker-status-container')) {
            return;
        }

        // Find the Website URL field
        // Look for label containing "Website URL" or "Website"
        const labels = Array.from(document.querySelectorAll('label.group-name'));
        let websiteUrlLabel = null;
        let websiteFormGroup = null;
        
        for (const label of labels) {
            const labelText = label.textContent.trim().toLowerCase();
            if (labelText.includes('website url') || labelText === 'website') {
                websiteUrlLabel = label;
                websiteFormGroup = label.closest('.form-group');
                break;
            }
        }

        if (!websiteFormGroup) {
            // Try alternative: look for input with name containing "website"
            const websiteInput = document.querySelector('input[name*="website" i], input[id*="website" i], input[placeholder*="website" i]');
            if (websiteInput) {
                websiteFormGroup = websiteInput.closest('.form-group');
            }
        }

        if (!websiteFormGroup) {
            console.warn('TDMchecker: Could not find Website URL field. Available labels:', 
                Array.from(document.querySelectorAll('label.group-name')).map(l => l.textContent.trim()));
            return;
        }
        
        console.log('TDMchecker: Found Website URL field, injecting TDM status after it');

        // Create the TDM status form group
        const tdmFormGroup = document.createElement('div');
        tdmFormGroup.className = 'form-group';
        tdmFormGroup.id = 'tdmchecker-status-container';

        const status = tdmData.status;
        let displayValue = 'null (not checked)';
        let statusClass = 'tdm-status-unknown';
        
        if (status !== null) {
            displayValue = status.opt_out ? 'true' : 'false';
            statusClass = status.opt_out ? 'tdm-status-opted-out' : 'tdm-status-not-opted-out';
        }

        // Get website URL from the form if not in tdmData
        let websiteUrl = tdmData.websiteUrl;
        if (!websiteUrl) {
            const websiteInput = websiteFormGroup.querySelector('input[name*="website" i], input[id*="website" i]');
            if (websiteInput) {
                websiteUrl = websiteInput.value;
            }
        }

        tdmFormGroup.innerHTML = `
            <label class="group-name">TDM opt out</label>
            <div class="group-controls">
                <span class="${statusClass}" id="tdm-status-${tdmData.feedId}">${displayValue}</span>
                ${websiteUrl ? `
                    <button type="button" class="btn" onclick="tdmcheckerForceCheck(${tdmData.feedId})" id="tdm-check-btn-${tdmData.feedId}" style="margin-left: 10px;">
                        Check TDM
                    </button>
                    <span id="tdm-check-status-${tdmData.feedId}" style="margin-left: 10px; display: none; font-size: 0.9em;"></span>
                ` : ''}
            </div>
        `;

        // Insert after the Website URL form group
        websiteFormGroup.insertAdjacentElement('afterend', tdmFormGroup);

        // Define force check function if not already defined
        if (typeof window.tdmcheckerForceCheck === 'undefined') {
            window.tdmcheckerForceCheck = function(feedId) {
                const btn = document.getElementById('tdm-check-btn-' + feedId);
                const statusSpan = document.getElementById('tdm-status-' + feedId);
                const checkStatus = document.getElementById('tdm-check-status-' + feedId);
                
                if (!btn || btn.disabled) return;
                
                btn.disabled = true;
                btn.textContent = 'Checking...';
                if (checkStatus) {
                    checkStatus.style.display = 'inline';
                    checkStatus.textContent = '';
                }
                
                // Get CSRF token and check URL from context or form
                const csrfToken = (tdmData && tdmData.csrfToken) || 
                                 document.querySelector('input[name="_csrf"]')?.value || 
                                 '';
                const checkUrl = (tdmData && tdmData.checkUrl) || 
                               window.location.origin + '/i/extensions/TDMchecker/configure';
                
                const formData = new FormData();
                formData.append('_csrf', csrfToken);
                formData.append('check_feed_id', feedId);
                formData.append('ajax', '1');
                
                fetch(checkUrl, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(text => {
                    try {
                        const data = JSON.parse(text);
                        if (data.status === 'success') {
                            if (statusSpan) {
                                statusSpan.textContent = data.opt_out ? 'true' : 'false';
                                statusSpan.className = data.opt_out ? 'tdm-status-opted-out' : 'tdm-status-not-opted-out';
                            }
                            if (checkStatus) {
                                checkStatus.textContent = '✓ Checked';
                                checkStatus.style.color = '#00a32a';
                            }
                        } else {
                            if (checkStatus) {
                                checkStatus.textContent = '✗ Error: ' + (data.message || 'Unknown error');
                                checkStatus.style.color = '#d63638';
                            }
                        }
                    } catch (e) {
                        // If not JSON, might be HTML redirect - reload page
                        if (text.includes('gen.action.done') || text.includes('success')) {
                            location.reload();
                        } else {
                            if (checkStatus) {
                                checkStatus.textContent = '✗ Error: Invalid response';
                                checkStatus.style.color = '#d63638';
                            }
                        }
                    }
                })
                .catch(error => {
                    if (checkStatus) {
                        checkStatus.textContent = '✗ Error: ' + error.message;
                        checkStatus.style.color = '#d63638';
                    }
                })
                .finally(() => {
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = 'Check TDM';
                    }
                    if (checkStatus) {
                        setTimeout(() => {
                            checkStatus.style.display = 'none';
                        }, 3000);
                    }
                });
            };
        }
    }

    // Inject CSS if provided in context
    function injectCSS() {
        if (typeof window.context !== 'undefined') {
            let css = null;
            if (typeof window.context.get === 'function') {
                css = window.context.get('tdmchecker_css');
            } else if (window.context.tdmchecker_css) {
                css = window.context.tdmchecker_css;
            }
            
            if (css && !document.getElementById('tdmchecker-styles')) {
                const style = document.createElement('style');
                style.id = 'tdmchecker-styles';
                style.textContent = css;
                document.head.appendChild(style);
            }
        }
    }

    // Wait for context to be loaded
    function init() {
        // Inject CSS first
        injectCSS();
        
        // Then inject TDM status
        if (typeof window.context !== 'undefined') {
            if (typeof window.context.get === 'function' || window.context.controller) {
                injectTDMStatus();
            } else {
                // Wait for the context to load
                document.addEventListener('freshrss:globalContextLoaded', function() {
                    injectCSS();
                    injectTDMStatus();
                }, false);
            }
        } else {
            // Wait for the context to load
            document.addEventListener('freshrss:globalContextLoaded', function() {
                injectCSS();
                injectTDMStatus();
            }, false);
        }
    }

    // Run when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Also try after delays in case the page loads dynamically
    setTimeout(init, 500);
    setTimeout(init, 1500);
    setTimeout(init, 3000);
})();
