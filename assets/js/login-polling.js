jQuery(document).ready(function($) {
    const token = $('#wplg-token').val();

    console.log('Polling started for token:', token);
    
    if (!token) return;
    
    $('.wplg-spinner').addClass('active');
    
    let pollCount = 0;
    const maxPolls = 150; // 5 minutes (150 * 2 seconds = 300 seconds)
    
    // Poll every 2 seconds
    const pollInterval = setInterval(async function() {
        pollCount++;
        
        // Stop polling after max attempts (5 minutes)
        if (pollCount >= maxPolls) {
            clearInterval(pollInterval);
            alert('Verification timeout. Please refresh the page to try again.');
            return;
        }
        
        try {
            const response = await fetch(wplgngrd.ajax_url + token);
            
            // If token not found, just keep polling (don't alert yet)
            if (!response.ok) {
                console.log('Token not confirmed yet, continuing to poll...');
                return;
            }
            
            const data = await response.json();
            
            if (data.status === 'confirmed') {
                clearInterval(pollInterval);
                window.location.href = '?wplg_select=1&token=' + token;
            } else if (data.status === 'cancelled') {
                clearInterval(pollInterval);
                alert('Verification cancelled. Please refresh to try again.');
            }
            // 'pending' status - just keep polling silently
        } catch (error) {
            console.error('Polling error:', error);
            // Don't alert, just keep polling
        }
    }, 2000);
});