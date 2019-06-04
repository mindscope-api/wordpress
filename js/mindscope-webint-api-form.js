// Example starter JavaScript for disabling form submissions if there are invalid fields

jQuery('#validatedCustomFile').on('change', function() { 
   let fileName = jQuery(this).val().split('\\').pop(); 
   var loader_img = jQuery(this).next('.custom-file-label').html();

   //if (fileName.length > 22)
   //{
	   //var file_extension = fileName.substring(fileName.lastIndexOf('.'));
	   //fileName = fileName.substring(0, 22) + '...' + file_extension;
   //}
   jQuery(this).next('.custom-file-label').addClass("selected").html(fileName);
   
   var post_url = jQuery('#ajax_post_url').val();
   
   var options = {
	    beforeSubmit: function(arr, $form, options) {
			jQuery('#invalidFileType').hide();
			jQuery('#gifResumeSpinner').show();
		},
		success: function(data) {
			var cand_data = JSON.parse(data);
			
			if (cand_data.success == 'invalid') 
			{
				//alert('Invalid file');
				jQuery('#invalidFileType').show();
			}
			else
			{
				jQuery('#txtFirstName').val(cand_data.firstname);
				jQuery('#txtMiddleName').val(cand_data.middlename);
				jQuery('#txtLastName').val(cand_data.lastname);
				jQuery('#txtEmailAddress').val(cand_data.email);
				jQuery('#txtPhoneNumber').val(cand_data.phone);
				jQuery('#txtAddress1').val(cand_data.address1);
				jQuery('#txtAddress2').val(cand_data.address2);
				jQuery('#txtCity').val(cand_data.city);
				jQuery("#inputProvinceState option").each(function() {
				  if(jQuery(this).text() == cand_data.provincestate) {
					jQuery(this).attr('selected', 'selected');            
				  }                        
				});
				jQuery('#txtZipPostal').val(cand_data.zippostal);
			}
			
			jQuery('#gifResumeSpinner').hide();
		},
		data: {parseResume: 'true'},
		error: function(data) {
			jQuery('#gifResumeSpinner').hide();
			//alert("error!");
		}
   };
   
   jQuery('#mwaform').ajaxSubmit(options);
   
});

jQuery(function() {
       // bind 'myForm' and provide a simple callback function
	   
   var options = {
	    beforeSerialize: function ($form, options){
			//console.log(jQuery('#mwaform'));
			//return false;
			if(!(jQuery('.needs-validation')[0].checkValidity()))
			{
				jQuery('.needs-validation').addClass('was-validated');
				return false;
			}
		},
	    beforeSubmit: function(arr, $form, options)
		{
			jQuery('#invalidFileType').hide();
			jQuery('#invalidCaptcha').hide();
			jQuery('#formError').hide();
			jQuery('#btnMwaFormSubmit').prop('disabled', true);
			jQuery('#gifSubmitSpinner').show();
		},
		success: function(data) {
			var cand_data = JSON.parse(data);
			
			if (cand_data.success == 'true')
			{
				jQuery('#mwaFormMain').hide();
				jQuery('#mwaFormStatus').show();
			}
			else if (cand_data.success == 'invalid') 
			{
				//alert('Invalid file');
				jQuery('#invalidFileType').show();
				jQuery('#gifSubmitSpinner').hide();
				jQuery('#btnMwaFormSubmit').prop('disabled', false);
			}
			else if (cand_data.success == 'invalidcaptcha')
			{
				jQuery('#invalidCaptcha').show();
				jQuery('#gifSubmitSpinner').hide();
				jQuery('#btnMwaFormSubmit').prop('disabled', false);
			}
			else
			{
				jQuery('#formError').show();
				jQuery('#gifSubmitSpinner').hide();
				jQuery('#btnMwaFormSubmit').prop('disabled', false);
			}
		},
		error: function(data) {
			alert("error!");
		}
   };
   
   jQuery('#mwaform').ajaxForm(options);
 });
