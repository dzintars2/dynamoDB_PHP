/*
*/
$(document).ready(function(){

		//datu ģenrešanas izsaukums
		$("#generateData").click(function(){
			console.log("ģenerēju datus");
			$("#dialog").dialog("open");
			$.ajax({
				type: "POST",
				url: "dynamodb.php",
				async: true,
				data: {
					darbiba: "generetDatus"
				},
				success: function( data ) {
					$("#dialog").dialog("close");
					alert(data);
					console.log(data);

				},
			    error: function (request, status, error) {
			        $("#dialog").dialog("close");
			        alert(request.responseText);
			    }
			});
		});

		//datu ģenrešanas izsaukums
		$("#createTable").click(function(){
			console.log("veidoju tabulu");
			$("#dialog").dialog("open");
			$.ajax({
				type: "POST",
				url: "dynamodb.php",
				async: true,
				data: {
					darbiba: "createTable"
				},
				success: function( data ) {
					$("#dialog").dialog("close");
					alert(data);
					console.log(data);
				},
			    error: function (request, status, error) {
			        $("#dialog").dialog("close");
			        alert(request.responseText);
			    }
			});
		});

		//atskaites pieprasījums
		$(document).on("click","[id^='report']",function(){
			$("#dialog").dialog("open");
			var reportId = ($(this).attr("id")); //atskaites veids
			var reportName = $(this).find("h5").text();
			$.ajax({
				type: "POST",
				url: "dynamodb.php",
				dataType: "json",
				async: false,
				data: {
					darbiba: reportId
				},
				success: function( data ) {
					$("#dialog").dialog("close");
					console.log(data);
					attelotTabulu(data, reportName);
				},
				error: function (request, status, error) {
					$("#dialog").dialog("close");
			        alert(request.responseText);
			    }
			});
			console.log(reportId);
		});

    	$( "#dialog" ).dialog({modal: true, autoOpen: false, closeText: "hide" });
    	$( "#tabula" ).dialog({modal: true, autoOpen: false, width: 800, height: 500 });


    	function attelotTabulu(dati,virsraksts){
    		var table = $.makeTable(eval(dati));
			$("#tabula").html("");
			(table).appendTo("#tabula");
			$("#tabula").dialog('option', 'title', virsraksts);
			$("#tabula").dialog("open");
    	}
    	//tabulas izveidošana no JSON (autortiesības: https://stackoverflow.com/questions/1051061/convert-json-array-to-an-html-table-in-jquery)
    	$.makeTable = function (mydata) {
		    var table = $('<table border=1 class="table">');
		    var tblHeader = "<thead class='table-dark'><tr>";
		    for (var k in mydata[0]) tblHeader += "<th>" + k + "</th>";
		    tblHeader += "</tr></thead>";
		    $(tblHeader).appendTo(table);
		    $.each(mydata, function (index, value) {
		        var TableRow = "<tr>";
		        $.each(value, function (key, val) {
		            TableRow += "<td>" + val + "</td>";
		        });
		        TableRow += "</tr>";
		        $(table).append(TableRow);
		    });
		    return ($(table));
		};
});

