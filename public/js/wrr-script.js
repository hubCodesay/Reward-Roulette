(function($) {
    'use strict';

    class RewardRoulette {
        constructor() {
            this.canvas = document.getElementById('wrr-canvas');
            if (!this.canvas) return;

            this.ctx = this.canvas.getContext('2d');
            this.sectors = wrr_data.sectors || [];
            this.segments = this.sectors.length;
            this.arc = Math.PI * 2 / this.segments;
            this.startAngle = 0; // -Math.PI / 2 to start top
            this.spinTimeout = null;
            this.spinAngleStart = 10;
            this.spinTime = 0;
            this.spinTimeTotal = 0;
            
            // For CSS animation approach
            this.currentRotation = 0;

            this.init();
        }

        init() {
            // High DPI fix
            const size = 300;
            this.canvas.width = size;
            this.canvas.height = size;
            
            this.drawWheel();
            
            // Trigger popup logic (demo: click button to open)
            // Ideally this triggers automatically based on logic
            // For MVP we just check if overlay exists and show it (debug mode or logic)
            
            // Add trigger button to footer just for demo if not auto-triggered
            // $('body').append('<button id="wrr-demo-trigger" style="position:fixed;bottom:20px;left:20px;z-index:9999;">🎮 Play</button>');
            // $('#wrr-demo-trigger').on('click', () => $('#wrr-overlay').addClass('wrr-open'));

            $('#wrr-spin-btn').on('click', () => this.spin());
        }

        drawWheel() {
            if (this.segments === 0) return;

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
                this.ctx.fillText(text, -this.ctx.measureText(text).width / 2, 0);
                this.ctx.restore();
            }
        }

        spin() {
            const $btn = $('#wrr-spin-btn');
            $btn.prop('disabled', true).text('Spinning...');

            // Call Backend
            $.ajax({
                url: wrr_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'wrr_spin',
                    nonce: wrr_data.nonce
                },
                success: (res) => {
                    if (res.success) {
                        this.animateSpin(res.data.sector_id, res.data);
                    } else {
                        alert(res.data.message);
                        $btn.prop('disabled', false).text('SPIN NOW');
                    }
                },
                error: (err) => {
                    console.error(err);
                    alert('Error connecting to server.');
                    $btn.prop('disabled', false).text('SPIN NOW');
                }
            });
        }

        animateSpin(winnerId, resultData) {
            // Find index of winner
            // Note: DB ID vs Array Index. We assume sectors array is sorted same as backend.
            const winnerIndex = this.sectors.findIndex(s => s.id == winnerId);
            
            // Calculate angle
            // The pointer is at TOP (270deg or -90deg) usually, but our HTML pointer is CSS based.
            // If pointer is at top (0 deg visual), we need the segment to land there.
            // Canvas 0 is Right (3 o'clock). 
            // Pointer is at Top (12 o'clock), 270 deg / -90 deg.
            
            // Simplification: We rotate the canvas.
            // To land sector i at Top (-90deg or 270deg):
            // Angle of sector center = i * arc + arc/2
            // We want (Rotation + SectorAngle) % 360 = 270
            // Rotation = 270 - SectorAngle
            
            // Randomize landing within the sector to look natural
            const sectorAngle = (winnerIndex * this.arc) + (this.arc / 2);
            const sectorAngleDeg = sectorAngle * (180 / Math.PI);
            
            // Pointer is top center. Canvas is 0 at right.
            // So we need to rotate result so that sectorAngle is at -90deg (270deg).
            // Example: Sector 0 (0-72deg). Center 36. Top is 270.
            // Rotation = 270 - 36 = 234.
            
            const targetRotation = 270 - sectorAngleDeg; 
            const spins = 5 * 360; // 5 full spins
            const finalRotation = spins + targetRotation;
            
            // Apply CSS transition
            this.canvas.style.transform = `rotate(${finalRotation}deg)`;
            
            // Wait for transition end
            setTimeout(() => {
                this.showResult(resultData);
            }, 5000); // 5s matches CSS transition time
        }

        showResult(data) {
            $('#wrr-result').html(`
                <h3>${data.reward.name}</h3>
                <p>${data.message}</p>
            `).slideDown();
            
            $('#wrr-spin-btn').text('COMPLETED');
            // Confetti here
        }
    }

    $(document).ready(function() {
        // Init only if popup exists
        if ($('#wrr-overlay').length) {
            new RewardRoulette();
            
            // Auto open for demo/testing purposes
            // In prod this is controlled by PHP
            setTimeout(() => {
                $('#wrr-overlay').addClass('wrr-open');
            }, 1000);
        }
    });

})(jQuery);
