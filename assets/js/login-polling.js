jQuery(document).ready(function($) {
    const token = $('#wplg-token').val();
    
    if (!token) return;
    
    $('.wplg-spinner').addClass('active');
    
    // Poll every 2 seconds
    const pollInterval = setInterval(async function() {
        try {
            const response = await fetch(wplgngrd.ajax_url + token);
            const data = await response.json();
            
            if (data.status === 'confirmed') {
                clearInterval(pollInterval);
                // Redirect to number selection page
                window.location.href = '?wplg_select=1&token=' + token;
            } else if (data.status === 'cancelled' || data.status === 'expired') {
                clearInterval(pollInterval);
                alert('Verification cancelled or expired. Please refresh to try again.');
            }
        } catch (error) {
            console.error('Polling error:', error);
        }
    }, 2000);
});