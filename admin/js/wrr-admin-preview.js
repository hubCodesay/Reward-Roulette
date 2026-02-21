(function($) {
    'use strict';

    class WRRAdminPreview {
        constructor() {
            this.canvas = document.getElementById('wrr-admin-canvas');
            if (!this.canvas) return;

            this.ctx = this.canvas.getContext('2d');
            this.sectors = this.getSectorsFromDOM();
            this.segments = this.sectors.length;
            this.arc = Math.PI * 2 / (this.segments || 1);
            this.startAngle = 0; 
            
            this.init();
        }

        init() {
            // High DPI fix
            const size = 300;
            this.canvas.width = size;
            this.canvas.height = size;
            
            this.drawWheel();
            this.bindEvents();
        }

        getSectorsFromDOM() {
            const sectors = [];
            // Iterate over table rows (excluding the "New" row for simplicity first, or include it)
            // We want to capture the "New Sector" row separately or include it if filled?
            // Let's grab all rows inside tbody, except the last "New" one unless it has data.
            
            // Actually, simpler approach: Grab all inputs named 'sectors[ID][name]'
            $('input[name^="sectors["][name$="[name]"]').each(function() {
                const $row = $(this).closest('tr');
                const id = $row.find('input[name$="[id]"]').val();
                
                // Skip if unchecked "Active" checkbox? Yes, preview should reflect active set.
                // Or show all but grayed out? Frontend shows only active. Let's show only active.
                if (!$row.find('input[name$="[is_active]"]').is(':checked')) return;

                sectors.push({
                    name: $(this).val(),
                    color: $row.find('input[type="color"]').val(),
                    // other fields if needed for visual
                });
            });
            
            // Check "New Sector" row
            const newName = $('input[name="new_sector[name]"]').val();
            if (newName && newName.trim() !== '') {
                sectors.push({
                    name: newName,
                    color: $('input[name="new_sector[color]"]').val() || '#2271b1'
                });
            }

            return sectors;
        }

        drawWheel() {
            this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
            
            if (this.sectors.length === 0) {
                // Draw placeholder
                this.ctx.fillStyle = '#f0f0f1';
                this.ctx.beginPath();
                this.ctx.arc(150, 150, 140, 0, Math.PI * 2);
                this.ctx.fill();
                this.ctx.fillStyle = '#666';
                this.ctx.font = '16px sans-serif';
                this.ctx.fillText('Немає активних секторів', 60, 150);
                return;
            }

            this.segments = this.sectors.length;
            this.arc = Math.PI * 2 / this.segments;
            
            const cx = this.canvas.width / 2;
            const cy = this.canvas.height / 2;
            const radius = this.canvas.width / 2;

            for (let i = 0; i < this.segments; i++) {
                const angle = this.startAngle + i * this.arc;
                const sector = this.sectors[i];

                this.ctx.beginPath();
                this.ctx.arc(cx, cy, radius, angle, angle + this.arc, false);
                this.ctx.lineTo(cx, cy);
                this.ctx.fillStyle = sector.color;
                this.ctx.fill();
                this.ctx.stroke();
                this.ctx.save();

                // Text
                this.ctx.save();
                this.ctx.translate(cx + Math.cos(angle + this.arc / 2) * (radius - 50), 
                                  cy + Math.sin(angle + this.arc / 2) * (radius - 50));
                this.ctx.rotate(angle + this.arc / 2 + Math.PI / 2); // Rotate text to center
                const text = sector.name.length > 15 ? sector.name.substring(0, 12) + '...' : sector.name;
                this.ctx.fillStyle = '#fff';
                this.ctx.font = 'bold 14px sans-serif';
                this.ctx.shadowColor = "rgba(0,0,0,0.5)";
                this.ctx.shadowBlur = 4;
                this.ctx.textAlign = "center";
                this.ctx.fillText(text, 0, 0); // centered due to translate
                this.ctx.restore();
            }
        }

        bindEvents() {
            // Re-render on any input change in the form
            $(document).on('change input', 'form input, form select', () => {
                this.sectors = this.getSectorsFromDOM();
                this.drawWheel();
            });
        }
    }

    $(document).ready(function() {
        new WRRAdminPreview();
    });

})(jQuery);
