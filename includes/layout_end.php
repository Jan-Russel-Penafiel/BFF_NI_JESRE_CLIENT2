        </main>
    </section>
</div>

<script>
    function openModalById(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) {
            return;
        }

        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeModalById(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) {
            return;
        }

        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    document.addEventListener('click', function (event) {
        const openButton = event.target.closest('[data-modal-open]');
        if (openButton) {
            openModalById(openButton.getAttribute('data-modal-open'));
            return;
        }

        const closeButton = event.target.closest('[data-modal-close]');
        if (closeButton) {
            closeModalById(closeButton.getAttribute('data-modal-close'));
            return;
        }

        const overlay = event.target.closest('[data-modal-overlay]');
        if (overlay) {
            closeModalById(overlay.getAttribute('data-modal-overlay'));
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        document.querySelectorAll('[data-modal-box]').forEach(function (modal) {
            if (modal.classList.contains('flex')) {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }
        });
    });
</script>
</body>
</html>
