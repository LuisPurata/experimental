package com.example.intentgit

import androidx.appcompat.app.AppCompatActivity
import android.os.Bundle
import android.view.View
import android.widget.Button
import android.widget.EditText
import com.android.volley.Request
import com.android.volley.Response
import com.android.volley.toolbox.StringRequest


class MainActivity : AppCompatActivity() {



    lateinit var edittext_usuario: EditText
    lateinit var edittext_contrasena: EditText
    lateinit var button_iniciar: Button


    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        //Inicializar Variables
        val button_iniciar: Button = findViewById(R.id.boton_principal)
        val button2: Button = findViewById(R.id.buton_secundario)
        val edittext_usuario : EditText = findViewById(R.id.text1)
        val edittext_contrasena : EditText = findViewById(R.id.text2)

        //-------------------------------------------------------

        button_iniciar.visibility = View.GONE
        button_iniciar.setOnClickListener{
            edittext_usuario.visibility=View.VISIBLE //Ocultar algun elemento
            button2.visibility = View.VISIBLE
            button_iniciar.visibility = View.GONE

        }

        button2.setOnClickListener {
            edittext_usuario.visibility = View.GONE //Mostrar algun elemento
            button_iniciar.visibility = View.VISIBLE
            button2.visibility = View.GONE
        }

    }

    fun validarUsuario(URL: String){
        var stringRequest: StringRequest = StringRequest(Request.Method.GET, URL,
            Response.Listener<String> { response ->
                // Display the first 500 characters of the response string.
                //textView.text = "Response is: ${response.substring(0, 500)}"
            },
            Response.ErrorListener { //textView.text = "That didn't work!"
             })



    }
}
