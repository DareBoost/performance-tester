jQuery(document).ready(function($) {
	// Manage the launch of the analyse :
	// 	- send analysis request
	//	- then in case of success repeatedly call for report until it is ready
	function launchAnalysis() {
		$('#dbwp_loading').show();
		$('#dbwp_submit').attr('disabled', true);
		$('#dbwp_result').empty();
		$('#dbwp_analysisContainer').hide();

		var data = {
				action: 'new_analysis'
		};

		// Send the post ajax request to launch the analysis 
		$.post(ajaxurl, data, function(response) {
			// if there is an error, print the message 
			if ( response.error ) {
				$('#dbwp_loading').hide();
				$('#dbwp_result').html(response.message);
				$('#dbwp_submit').attr('disabled', false);
				$('#dbwp_analysisContainer').show();
				
			// if the analysis is launch we create an interval to repeatedly (each 2 secondes) call the get report 
			} else {
				$('#dbwp_result').html(response.message);
				var reportInterval = window.setInterval( function() {
					var getReportData = {
							action: 'get_report',
							reportId: response.reportId
					};
					
					$.post(ajaxurl, getReportData, function(response) {
						$('#dbwp_result').html(response.message);
						// if it's ended we remove laoding image and enable analyse button
						if ( response.isEnded ) {
							$('#dbwp_loading').hide();
							$('#dbwp_submit').attr('disabled', false);
							clearInterval(reportInterval);
						}
					});  
				}, 2000);
			}
		});	
		
		return false;
	}

	// when the analysis form is submited
	$('#dbwp_form').submit(function(){
		launchAnalysis();
		return false;
	});
	
	// When we got the hash "launch" on the url, it's mean we have to launch the analysis
	if ( window.location.hash === "#launch" ) {
		launchAnalysis();
		// once the analysis is launched, we remove the hash to not retrigger it in case of page refresh
		window.location.hash = "";
	}
});
