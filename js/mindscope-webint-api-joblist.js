jQuery(function() {
	jQuery('#api-job-list').show();
	
	mindscope_generate_job_list(mindscope_generate_filters(), true);
});

jQuery('.keyword-search-box').keyup(function(event) {
	if (event.keyCode === 13) {
		mindscope_generate_job_list(mindscope_generate_filters(), false);
	}
});

jQuery('.division-options').change(function() {
	mindscope_generate_job_list(mindscope_generate_filters(), false);
});

jQuery('.state-options').change(function() {
	mindscope_generate_job_list(mindscope_generate_filters(), false);
});

jQuery('.employment-options').change(function() {
	mindscope_generate_job_list(mindscope_generate_filters(), false);
});

jQuery('.api-search-button').click(function() {
	mindscope_generate_job_list(mindscope_generate_filters(), false);
});

function mindscope_generate_filters()
{
	var division_list = [];
	var state_list = [];
	var employment_list = [];
	var text_to_search = "";
	
	jQuery('.division-options:checkbox:checked').each(function() {
		division_list.push(jQuery(this).val());
	});
	
	jQuery('.state-options:checkbox:checked').each(function() {
		state_list.push(jQuery(this).val());
	});
	
	jQuery('.employment-options:checkbox:checked').each(function() {
		employment_list.push(jQuery(this).val());
	});
	
	if(jQuery('#searchInputMobile').is(':visible'))
	{
		text_to_search = jQuery('#searchInputMobile').val();
	}
	else if (jQuery('#searchInputDesktop').is(':visible'))
	{
		text_to_search = jQuery('#searchInputDesktop').val();
	}
	
	var filter_parameter = {division: '', state: '', employment: '', keyword: ''};
	
	if (division_list) {
		filter_parameter.division = division_list;
	}
	if (state_list) {
		filter_parameter.state = state_list;
	}
	if (employment_list) {
		filter_parameter.employment = employment_list;
	}
	if (text_to_search) {
		filter_parameter.keyword = text_to_search;
	}
	
	return filter_parameter;
}

function mindscope_generate_job_list(params, fromPageLoad)
{
	var ajax_url = jQuery('#ajax_post_url').val();
	
	var data = { action: 'mindscope_generate_job_list' };
	var extended_data = jQuery.extend(data, params);
	
	jQuery('#mwa_job_list').hide();
	jQuery('#gifDotsLoader').show();	
	
	jQuery.ajax({
	type: 'POST',
	data: extended_data,
	url: ajax_url,
	success: function (data) {
			//console.log(data);
			jQuery('#mwa_job_list').empty();
			jQuery('#mwa_job_list').html(data);
			
			var isChrome = /Chrome/.test(navigator.userAgent) && /Google Inc/.test(navigator.vendor); 
			var current_page = 1;
			
			if(isChrome && fromPageLoad){
				var page_number = jQuery(jQuery.parseHTML(data)).filter('#session_page_number').attr('value');
				
				current_page = Number(page_number);
			}
			else
			{
				mindscope_save_pagenumber(1);
			}
			jQuery('#gifDotsLoader').hide();
			jQuery('#mwa_job_list').show();
			
			var pageParts = jQuery(".paginate");

			// How many parts do we have?
			var numPages = pageParts.length;
			// How many parts do we want per page?
			var perPage = 8;
			
			if (current_page > 1)
			{
				var start = perPage * (current_page - 1);
				var end = start + perPage;

				// First hide all page parts
				// Then show those just for our page
				pageParts.hide()
						 .slice(start, end).show();
			}
			else
			{
				// When the document loads we're on page 1
				// So to start with... hide everything else
				pageParts.slice(perPage).hide();
			}
			
			jQuery('.paged-jobs').pagination({
				items: numPages,
				itemsOnPage: perPage,
				cssStyle: "light-theme",
				useAnchors: false,
				displayedPages: 1,
				//prevText: '<',
				//nextText: '>',
				edges: 1,
				currentPage: current_page,
				// We implement the actual pagination
				//   in this next function. It runs on
				//   the event that a user changes page
				onPageClick: function(pageNum) {
					console.log(pageNum);
					// Which page parts do we show?
					var start = perPage * (pageNum - 1);
					var end = start + perPage;
 
					// First hide all page parts
					// Then show those just for our page
					pageParts.hide()
							 .slice(start, end).show();
					
					if(isChrome) {
						mindscope_save_pagenumber(pageNum);
					}
							 
					jQuery('html, body').animate({
						scrollTop: jQuery('#mwa_job_list').offset().top - 100//#DIV_ID is an example. Use the id of your destination on the page
					}, 'slow');
				}
			});
		},
	error: function() {
		jQuery('#mwa_job_list').empty();
		jQuery('#mwa_job_list').html('<p>Unable to get job list. Try again later.</p>');
		jQuery('#gifDotsLoader').hide();
		jQuery('#mwa_job_list').show();
	},
	statusCode: {
		500: function() {
			jQuery('#mwa_job_list').empty();
			jQuery('#mwa_job_list').html('<p>Unable to get job list. Try again later.</p>');
			jQuery('#gifDotsLoader').hide();
			jQuery('#mwa_job_list').show();
		}
	}
	});
}

function mindscope_save_pagenumber(pageNum)
{
	var ajax_url = jQuery('#ajax_post_url').val();
	
	jQuery.ajax({
		type: 'POST',
		data: { action: 'mindscope_save_page_number', page_number: pageNum },
		url: ajax_url,
		success: function(data)
		{
		}
	});
}

