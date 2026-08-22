(function () {
    'use strict';

    const DEBOUNCE_MS = 900;

    class RepresentativeEnrollmentAutosave {
        constructor(root) {
            this.root = root;
            this.forms = Array.from(root.querySelectorAll('[data-enrollment-autosave]'));
            this.states = new Map();
            this.requestQueue = Promise.resolve();
            this.navigationInProgress = false;
        }

        init() {
            if (typeof window.fetch !== 'function' || typeof window.FormData !== 'function') {
                return;
            }

            this.forms.forEach((form) => this.initializeForm(form));
            this.initializeNavigation();
            window.addEventListener('beforeunload', (event) => this.warnBeforeExit(event));
            window.addEventListener('pagehide', () => this.attemptExitSave());
        }

        initializeForm(form) {
            const state = {
                mode: 'clean',
                revision: 0,
                timer: null,
                inFlight: null,
            };
            this.states.set(form, state);
            form.querySelectorAll('[data-enrollment-fallback-save]').forEach((button) => {
                button.classList.add('d-none');
            });
            this.initializeMedicalFields(form);

            form.addEventListener('input', (event) => this.handleInput(form, event));
            form.addEventListener('change', (event) => {
                if (event.target && event.target.tagName === 'SELECT') {
                    this.handleInput(form, event);
                }
            });
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                this.cancelTimer(state);
                this.save(form, true);
            });
        }

        handleInput(form, event) {
            const target = event.target;
            if (target && target.matches('[data-medical-controller]') && target.checked) {
                this.syncMedicalField(form, target.name, true);
            }
            this.markDirty(form);
        }

        markDirty(form) {
            const state = this.states.get(form);
            state.revision += 1;
            state.mode = 'dirty';
            this.setStatus(form, '');
            this.clearErrors(form);
            this.schedule(form, DEBOUNCE_MS);
        }

        schedule(form, delay) {
            const state = this.states.get(form);
            this.cancelTimer(state);
            state.timer = window.setTimeout(() => {
                state.timer = null;
                this.save(form, false);
            }, delay);
        }

        save(form, reportValidity) {
            const state = this.states.get(form);
            if (state.inFlight) {
                return state.inFlight;
            }

            const queued = this.requestQueue.then(() => this.performSave(form, reportValidity));
            this.requestQueue = queued.catch(() => false);
            state.inFlight = queued.finally(() => {
                state.inFlight = null;
            });

            return state.inFlight;
        }

        async performSave(form, reportValidity) {
            const state = this.states.get(form);
            this.cancelTimer(state);
            if (!form.checkValidity()) {
                state.mode = 'dirty';
                if (reportValidity) {
                    form.reportValidity();
                    this.showErrors(form, ['Complete the required fields before leaving this section.']);
                    this.focusFailure(form);
                }

                return false;
            }

            const sentRevision = state.revision;
            state.mode = 'saving';
            this.setStatus(form, 'Saving...');
            this.clearErrors(form);

            try {
                const response = await window.fetch(form.action, {
                    method: 'POST',
                    body: new window.FormData(form),
                    headers: {Accept: 'application/json'},
                    credentials: 'same-origin',
                    redirect: 'manual',
                });
                const contentType = response.headers.get('Content-Type') || '';
                if (!contentType.toLowerCase().includes('application/json')) {
                    throw new Error('Unexpected autosave response.');
                }
                const payload = await response.json();
                this.refreshCsrf(payload.csrfToken);

                if (response.ok && payload.ok === true && payload.state === 'saved') {
                    this.updateProgress(payload.section, payload.sectionStatus);
                    if (state.revision === sentRevision) {
                        state.mode = 'clean';
                        this.setStatus(form, 'Saved');
                        this.clearErrors(form);
                    } else {
                        state.mode = 'dirty';
                        this.setStatus(form, '');
                        this.schedule(form, 0);
                    }

                    return true;
                }

                if (state.revision !== sentRevision) {
                    state.mode = 'dirty';
                    this.schedule(form, 0);

                    return true;
                }

                state.mode = 'error';
                this.setStatus(form, 'Save error');
                this.showErrors(
                    form,
                    Array.isArray(payload.errors) ? payload.errors : ['The information could not be saved.'],
                    payload.reloadRequired === true,
                    typeof payload.redirect === 'string' ? payload.redirect : null,
                );

                return false;
            } catch (error) {
                if (state.revision !== sentRevision) {
                    state.mode = 'dirty';
                    this.schedule(form, 0);

                    return true;
                }
                state.mode = 'error';
                this.setStatus(form, 'Save error');
                this.showErrors(form, ['The information could not be saved.']);

                return false;
            }
        }

        async flushAll() {
            for (const form of this.forms) {
                const state = this.states.get(form);
                this.cancelTimer(state);
                while (state.mode !== 'clean') {
                    const saved = await this.save(form, true);
                    if (!saved || state.mode === 'error') {
                        this.focusFailure(form);

                        return false;
                    }
                }
            }

            return true;
        }

        initializeNavigation() {
            this.root.querySelectorAll('a[data-enrollment-navigation]').forEach((link) => {
                link.addEventListener('click', async (event) => {
                    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                        return;
                    }
                    event.preventDefault();
                    await this.navigate(() => window.location.assign(link.href));
                });
            });
            this.root.querySelectorAll('form[data-enrollment-navigation]').forEach((form) => {
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    await this.navigate(() => window.HTMLFormElement.prototype.submit.call(form));
                });
            });
        }

        async navigate(continueNavigation) {
            if (this.navigationInProgress) {
                return;
            }
            this.navigationInProgress = true;
            const saved = await this.flushAll();
            this.navigationInProgress = false;
            if (saved) {
                continueNavigation();
            }
        }

        warnBeforeExit(event) {
            if (!this.hasUnsafeState()) {
                return;
            }
            event.preventDefault();
            event.returnValue = '';
        }

        attemptExitSave() {
            const form = this.forms.find((candidate) => {
                const state = this.states.get(candidate);

                return state.inFlight === null && (state.mode === 'dirty' || state.mode === 'error');
            });
            if (!form || !form.checkValidity()) {
                return;
            }
            window.fetch(form.action, {
                method: 'POST',
                body: new window.FormData(form),
                headers: {Accept: 'application/json'},
                credentials: 'same-origin',
                keepalive: true,
            }).catch(() => undefined);
        }

        hasUnsafeState() {
            return this.forms.some((form) => this.states.get(form).mode !== 'clean');
        }

        initializeMedicalFields(form) {
            form.querySelectorAll('[data-medical-controller]').forEach((control) => {
                if (control.checked) {
                    this.syncMedicalField(form, control.name, control.value === '0');
                }
            });
        }

        syncMedicalField(form, controllerName, clearWhenNo) {
            const selected = form.querySelector(`[name="${controllerName}"]:checked`);
            const detail = form.querySelector(`[data-medical-detail-for="${controllerName}"]`);
            const container = form.querySelector(`[data-medical-dependent-for="${controllerName}"]`);
            const enabled = selected && selected.value === '1';
            if (!detail || !container) {
                return;
            }
            if (!enabled && clearWhenNo) {
                detail.value = '';
            }
            detail.disabled = !enabled;
            detail.required = Boolean(enabled);
            container.hidden = !enabled;
        }

        refreshCsrf(token) {
            if (typeof token !== 'string' || token === '') {
                return;
            }
            this.root.querySelectorAll('input[name="_csrf_token"]').forEach((input) => {
                input.value = token;
            });
        }

        updateProgress(section, status) {
            if (typeof section !== 'string' || !['PENDING', 'COMPLETE'].includes(status)) {
                return;
            }
            const item = this.root.querySelector(`[data-progress-section="${section}"]`);
            if (item) {
                item.textContent = status === 'COMPLETE' ? 'Complete' : 'Pending';
            }
        }

        setStatus(form, message) {
            const status = form.querySelector('[data-enrollment-autosave-status]');
            if (status) {
                status.textContent = message;
            }
        }

        clearErrors(form) {
            const container = form.querySelector('[data-enrollment-autosave-errors]');
            if (container) {
                container.replaceChildren();
                container.hidden = true;
            }
            form.querySelectorAll('[data-enrollment-fallback-save]').forEach((button) => {
                button.classList.add('d-none');
            });
        }

        showErrors(form, errors, reloadRequired, redirect) {
            const container = form.querySelector('[data-enrollment-autosave-errors]');
            if (!container) {
                return;
            }
            container.replaceChildren();
            const list = document.createElement('ul');
            errors.forEach((message) => {
                const item = document.createElement('li');
                item.textContent = String(message);
                list.appendChild(item);
            });
            container.appendChild(list);
            if (reloadRequired) {
                const reload = document.createElement('button');
                reload.type = 'button';
                reload.className = 'btn btn-outline-secondary';
                reload.textContent = 'Reload';
                reload.addEventListener('click', () => window.location.reload());
                container.appendChild(reload);
            } else if (redirect) {
                const link = document.createElement('a');
                link.href = redirect;
                link.className = 'btn btn-outline-secondary';
                link.textContent = 'Continue';
                container.appendChild(link);
            }
            container.hidden = false;
            form.querySelectorAll('[data-enrollment-fallback-save]').forEach((button) => {
                button.classList.remove('d-none');
            });
        }

        focusFailure(form) {
            const error = form.querySelector('[data-enrollment-autosave-errors]');
            if (error) {
                error.scrollIntoView({block: 'center'});
                error.focus();
            }
        }

        cancelTimer(state) {
            if (state.timer !== null) {
                window.clearTimeout(state.timer);
                state.timer = null;
            }
        }
    }

    window.RepresentativeEnrollmentAutosave = RepresentativeEnrollmentAutosave;
    document.addEventListener('DOMContentLoaded', () => {
        new RepresentativeEnrollmentAutosave(document).init();
    });
}());
