document.addEventListener('DOMContentLoaded', () => {
    const savedTheme = localStorage.getItem('pfm-theme');
    if (savedTheme === 'dark') {
        document.body.classList.add('dark-mode');
    }

    const themeToggle = document.querySelector('[data-theme-toggle]');
    if (themeToggle) {
        const updateThemeLabel = () => {
            const darkMode = document.body.classList.contains('dark-mode');
            const icon = themeToggle.querySelector('i');
            const label = themeToggle.querySelector('[data-theme-label]');
            if (icon) {
                icon.className = darkMode ? 'bi bi-sun' : 'bi bi-moon-stars';
            }
            if (label) {
                label.textContent = darkMode ? 'Light mode' : 'Dark mode';
            }
            themeToggle.setAttribute('aria-label', darkMode ? 'Switch to light mode' : 'Switch to dark mode');
            themeToggle.classList.toggle('is-dark', darkMode);
        };

        updateThemeLabel();
        themeToggle.addEventListener('click', () => {
            const darkMode = document.body.classList.toggle('dark-mode');
            localStorage.setItem('pfm-theme', darkMode ? 'dark' : 'light');
            updateThemeLabel();
        });
    }

    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.reset();
        document.getElementById('loginUsername').value = '';
        document.getElementById('loginPassword').value = '';
    }

    const registerForm = document.getElementById('registerForm');
    if (registerForm) {
        registerForm.reset();
        ['registerUsername', 'registerEmail', 'registerPassword', 'registerConfirmPassword'].forEach((id) => {
            document.getElementById(id).value = '';
        });
    }

    const menuToggle = document.querySelector('[data-menu-toggle]');
    const menuDropdown = document.querySelector('[data-menu-dropdown]');
    if (menuToggle && menuDropdown) {
        menuToggle.addEventListener('click', (event) => {
            event.stopPropagation();
            const isOpen = menuDropdown.classList.toggle('open');
            menuToggle.setAttribute('aria-expanded', String(isOpen));
        });

        document.addEventListener('click', () => {
            menuDropdown.classList.remove('open');
            menuToggle.setAttribute('aria-expanded', 'false');
        });
    }

    const notificationToggle = document.querySelector('[data-notification-toggle]');
    const notificationDropdown = document.querySelector('[data-notification-dropdown]');
    if (notificationToggle && notificationDropdown) {
        let notificationsMarkedRead = false;
        notificationToggle.addEventListener('click', (event) => {
            event.stopPropagation();
            const isOpen = notificationDropdown.classList.toggle('open');
            notificationToggle.setAttribute('aria-expanded', String(isOpen));

            if (isOpen && !notificationsMarkedRead) {
                notificationsMarkedRead = true;
                fetch('mark_notifications_read.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).then(() => {
                    const badge = notificationToggle.querySelector('.notification-badge');
                    if (badge) {
                        badge.remove();
                    }
                }).catch(() => {
                    notificationsMarkedRead = false;
                });
            }
        });

        document.addEventListener('click', () => {
            notificationDropdown.classList.remove('open');
            notificationToggle.setAttribute('aria-expanded', 'false');
        });
    }

    document.querySelectorAll('[data-confirm]').forEach((element) => {
        element.addEventListener('click', (event) => {
            if (!window.confirm(element.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });

    // Budget page: only run these event bindings on the set_budget page (where `#budgetForm` exists)
    const isBudgetPage = !!document.getElementById('budgetForm');
    if (isBudgetPage) {
        const resetBudgetsLink = document.querySelector('[data-reset-budgets]');
        if (resetBudgetsLink) {
            resetBudgetsLink.addEventListener('click', () => {
                if (!window.confirm('Set all budgets to 0? This will remove all budget limits for this month.')) {
                    return;
                }

                document.querySelectorAll('input[name^="budget["]').forEach((input) => {
                    input.value = '';
                });
            });
        }

        const newBudgetIcon = document.getElementById('newBudgetIcon');
        const newBudgetIconPreview = document.querySelector('[data-new-budget-icon-preview]');
        if (newBudgetIcon && newBudgetIconPreview) {
            newBudgetIcon.addEventListener('change', () => {
                newBudgetIconPreview.className = `bi ${newBudgetIcon.value}`;
            });
        }

        document.querySelectorAll('[data-budget-icon-select]').forEach((iconSelect) => {
            iconSelect.addEventListener('change', () => {
                const preview = document.querySelector(`[data-budget-icon-preview="${iconSelect.dataset.budgetIconSelect}"]`);
                if (preview) {
                    preview.className = `bi ${iconSelect.value}`;
                }
            });
        });

        document.querySelectorAll('[data-budget-row-edit]').forEach((editButton) => {
            editButton.addEventListener('click', () => {
                const categoryId = editButton.dataset.budgetRowEdit;
                const budgetInput = document.getElementById(`budget_${categoryId}`);
                const iconSelect = document.querySelector(`[data-budget-icon-select="${categoryId}"]`);
                const nameInput = document.querySelector(`[data-budget-name="${categoryId}"]`);
                if (!budgetInput || !iconSelect || !nameInput) {
                    return;
                }

                const editing = budgetInput.readOnly;
                budgetInput.readOnly = !editing;
                iconSelect.disabled = !editing;
                iconSelect.classList.toggle('d-none', !editing);
                nameInput.readOnly = !editing;
                nameInput.classList.toggle('form-control-plaintext', !editing);
                nameInput.classList.toggle('form-control', editing);
                editButton.innerHTML = editing
                    ? '<i class="bi bi-check2"></i>'
                    : '<i class="bi bi-pencil"></i>';
                editButton.title = editing ? 'Finish editing' : 'Edit budget';

                if (editing) {
                    nameInput.focus();
                    nameInput.select();
                }
            });
        });

        const budgetForm = document.getElementById('budgetForm');
        if (budgetForm) {
            budgetForm.addEventListener('submit', () => {
                document.querySelectorAll('[data-budget-icon-select]:disabled').forEach((iconSelect) => {
                    iconSelect.disabled = false;
                });
            });
        }
    }

    document.querySelectorAll('[data-budget-edit]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.budgetEdit);
            if (!input) {
                return;
            }

            input.readOnly = !input.readOnly;
            button.innerHTML = input.readOnly
                ? '<i class="bi bi-pencil"></i> Edit'
                : '<i class="bi bi-check2"></i> Done';

            if (!input.readOnly) {
                input.focus();
                input.select();
            }
        });
    });

    const iconSelect = document.getElementById('iconSelect');
    const iconPreview = document.querySelector('#iconPreview i');
    if (iconSelect && iconPreview) {
        iconSelect.addEventListener('change', () => {
            iconPreview.className = `bi ${iconSelect.value}`;
        });
    }

    const colorPicker = document.getElementById('colorPicker');
    const colorPreview = document.querySelector('#colorPreview .color-preview');
    if (colorPicker && colorPreview) {
        colorPicker.addEventListener('input', () => {
            colorPreview.style.backgroundColor = colorPicker.value;
        });
    }

    const profilePictureInput = document.getElementById('profilePictureInput');
    const profilePicturePreview = document.getElementById('profilePicturePreview');
    if (profilePictureInput && profilePicturePreview) {
        profilePictureInput.addEventListener('change', () => {
            const file = profilePictureInput.files[0];
            if (!file || !file.type.startsWith('image/')) {
                return;
            }

            if (profilePicturePreview.tagName === 'IMG') {
                profilePicturePreview.src = URL.createObjectURL(file);
                return;
            }

            const previewImage = document.createElement('img');
            previewImage.id = 'profilePicturePreview';
            previewImage.className = 'profile-avatar';
            previewImage.alt = 'Profile picture preview';
            previewImage.src = URL.createObjectURL(file);
            profilePicturePreview.replaceWith(previewImage);
        });
    }

});
