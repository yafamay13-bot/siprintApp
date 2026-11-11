document.addEventListener('DOMContentLoaded', () => {
  const menuButton = document.querySelector('.mobile-menu');
  const sidebar = document.querySelector('.sidebar');
  const overlay = document.querySelector('.mobile-overlay');

  function closeMenu() {
    if (!sidebar || !menuButton || !overlay) return;
    sidebar.classList.remove('sidebar-open');
    overlay.classList.remove('overlay-visible');
    menuButton.setAttribute('aria-expanded', 'false');
  }

  menuButton?.addEventListener('click', () => {
    const isOpen = sidebar.classList.toggle('sidebar-open');
    overlay?.classList.toggle('overlay-visible', isOpen);
    menuButton.setAttribute('aria-expanded', String(isOpen));
  });
  overlay?.addEventListener('click', closeMenu);
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeMenu();
  });

  const profilePhotoInput = document.querySelector('#profile-photo');
  if (profilePhotoInput) {
    profilePhotoInput.addEventListener('change', () => {
      const [file] = profilePhotoInput.files;
      if (!file) return;
      const preview = document.querySelector('.profile-avatar-image');
      const initial = document.querySelector('.profile-avatar-initial');
      const submit = profilePhotoInput.form.querySelector('.profile-photo-submit');
      if (preview && initial) {
        preview.src = URL.createObjectURL(file);
        preview.hidden = false;
        initial.hidden = true;
      }
      if (submit) submit.hidden = false;
    });
  }

  const accountHelpButton = document.querySelector('.login-account-help');
  const accountHelpDetail = document.querySelector('#login-account-detail');
  accountHelpButton?.addEventListener('click', () => {
    const expanded = accountHelpButton.getAttribute('aria-expanded') === 'true';
    accountHelpButton.setAttribute('aria-expanded', String(!expanded));
    accountHelpDetail.hidden = expanded;
  });
  document.querySelector('.login-access-note')?.addEventListener('click', (event) => {
    event.preventDefault();
    document.querySelector('#email')?.focus();
  });

  const dropdowns = [
    { button: document.querySelector('.notification-trigger'), menu: document.querySelector('#notification-menu') },
    { button: document.querySelector('.topbar-profile-trigger'), menu: document.querySelector('#profile-menu') }
  ].filter((dropdown) => dropdown.button && dropdown.menu);

  function closeDropdowns(except = null) {
    dropdowns.forEach(({ button, menu }) => {
      if (menu === except) return;
      menu.hidden = true;
      button.setAttribute('aria-expanded', 'false');
    });
  }

  dropdowns.forEach(({ button, menu }) => {
    button.addEventListener('click', () => {
      const shouldOpen = menu.hidden;
      closeDropdowns(menu);
      menu.hidden = !shouldOpen;
      button.setAttribute('aria-expanded', String(shouldOpen));
    });
  });

  document.addEventListener('click', (event) => {
    if (!event.target.closest('.notification-control, .profile-control')) closeDropdowns();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeDropdowns();
  });

  document.querySelectorAll('.alert-close').forEach((button) => {
    button.addEventListener('click', () => button.closest('.alert').remove());
  });

  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
  });

  const documentPreview = document.querySelector('.document-preview-page');
  const previewFrame = document.querySelector('.document-preview-frame');
  const printButton = document.querySelector('.document-print-button');
  const printDocument = () => {
    if (!previewFrame?.contentWindow) return;
    previewFrame.contentWindow.focus();
    previewFrame.contentWindow.print();
  };
  if (previewFrame && printButton) {
    let previewLoaded = false;
    const printWhenReady = () => {
      if (previewLoaded) {
        printDocument();
        return;
      }
      printButton.disabled = true;
      previewFrame.addEventListener('load', () => {
        previewLoaded = true;
        printButton.disabled = false;
        printDocument();
      }, { once: true });
    };
    previewFrame.addEventListener('load', () => {
      previewLoaded = true;
      printButton.disabled = false;
      if (documentPreview?.dataset.autoPrint === 'true') printDocument();
    }, { once: true });
    printButton.addEventListener('click', printWhenReady);
    if (documentPreview?.dataset.autoPrint === 'true') printButton.disabled = true;
  }

  document.querySelector('.password-toggle')?.addEventListener('click', (event) => {
    const input = document.querySelector('#password');
    const visible = input.type === 'text';
    input.type = visible ? 'password' : 'text';
    event.currentTarget.setAttribute('aria-label', visible ? 'Tampilkan kata sandi' : 'Sembunyikan kata sandi');
    event.currentTarget.setAttribute('title', visible ? 'Tampilkan kata sandi' : 'Sembunyikan kata sandi');
    event.currentTarget.classList.toggle('password-visible', !visible);
  });

  const fileInput = document.querySelector('#document');
  fileInput?.addEventListener('change', () => {
    const label = document.querySelector('[data-file-name]');
    const file = fileInput.files?.[0];
    if (label) label.textContent = file ? `${file.name} · ${(file.size / (1024 * 1024)).toFixed(2)} MB` : 'Belum ada file dipilih';
  });

  document.querySelectorAll('[data-number-step]').forEach((button) => {
    button.addEventListener('click', () => {
      const input = document.querySelector('#copies');
      if (!input) return;
      const next = Number(input.value || 1) + Number(button.dataset.numberStep);
      input.value = String(Math.max(Number(input.min), Math.min(Number(input.max), next)));
      input.dispatchEvent(new Event('input', { bubbles: true }));
    });
  });

  document.querySelectorAll('[data-status-form]').forEach((form) => {
    const select = form.querySelector('.status-select');
    const reason = form.querySelector('.rejection-input');
    const updateReason = () => {
      const isRejected = select.value === 'ditolak';
      reason.hidden = !isRejected;
      reason.required = isRejected;
    };
    select.addEventListener('change', updateReason);
    updateReason();
  });

  document.querySelector('[data-table-search]')?.addEventListener('input', (event) => {
    const query = event.currentTarget.value.trim().toLocaleLowerCase('id');
    const table = document.querySelector('.searchable-table');
    const rows = table?.querySelectorAll('tbody tr') || [];
    let visibleRows = 0;
    rows.forEach((row) => {
      const matches = row.textContent.toLocaleLowerCase('id').includes(query);
      row.hidden = !matches;
      if (matches) visibleRows += 1;
    });
    const emptyMessage = document.querySelector('.no-search-results');
    if (emptyMessage) emptyMessage.hidden = visibleRows > 0;
  });
});
