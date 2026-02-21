<div id="wrr-overlay" class="wrr-overlay">
    <div class="wrr-modal">
        <button class="wrr-close-btn" onclick="document.getElementById('wrr-overlay').style.display='none'">&times;</button>
        
        <div class="wrr-header">
            <h2>🎉 Spin & Win!</h2>
            <p>Try your luck to win exclusive prizes.</p>
        </div>

        <div class="wrr-game-area">
            <div class="wrr-wheel-wrapper">
                <div class="wrr-pointer">▼</div>
                <canvas id="wrr-canvas" width="300" height="300"></canvas>
            </div>
        </div>

        <div class="wrr-controls">
            <button id="wrr-spin-btn" class="wrr-btn-primary">SPIN NOW</button>
        </div>
        
        <div id="wrr-result" class="wrr-result" style="display:none;"></div>
    </div>
</div>
