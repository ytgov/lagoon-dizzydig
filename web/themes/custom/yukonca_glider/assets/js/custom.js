jQuery('button#menu-button').click( function () {
   jQuery('body').addClass('active'); 
});

jQuery('button.closebtn').click( function () {
   jQuery('body').removeClass('active'); 
});

jQuery('#wb-srch form input[type="search"]').attr("placeholder", "Keywords");

